<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use App\Models\Order;
use App\Models\OrderSettlement;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AdminFinancialReportService
{
    public function report(array $query): array
    {
        if (($query['view'] ?? null) === 'workspace') {
            return $this->workspace($query);
        }
        $input = validator($query, [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'granularity' => ['nullable', 'in:day,week,month'], 'status' => ['nullable', 'string', 'max:32'],
            'currency' => ['nullable', 'string', 'size:3'],
        ])->validate();
        $timezone = (string) config('app.report_timezone', 'Europe/Moscow');
        $fromDate = CarbonImmutable::createFromFormat('Y-m-d', $input['from'] ?? now($timezone)->startOfMonth()->toDateString(), $timezone)->startOfDay();
        $toDate = CarbonImmutable::createFromFormat('Y-m-d', $input['to'] ?? now($timezone)->toDateString(), $timezone)->startOfDay();
        $toExclusive = $toDate->addDay();
        $fromInstant = $fromDate->setTimezone('UTC');
        $toExclusiveInstant = $toExclusive->setTimezone('UTC');
        $from = $fromDate->toDateString();
        $to = $toDate->toDateString();
        $orders = Order::query()->where('created_at', '>=', $fromInstant)->where('created_at', '<', $toExclusiveInstant);
        if (! empty($input['status'])) $orders->where('payment_status', $input['status']);
        if (! empty($input['currency'])) $orders->where('currency', strtoupper($input['currency']));
        $currencyRows = (clone $orders)->select('currency')->selectRaw('COUNT(*) orders, COALESCE(SUM(amount_final),0) gross_amount, COALESCE(SUM(amount_discount),0) discount_amount')->groupBy('currency')->orderBy('currency')->get();
        $moneyStatus = $currencyRows->count() > 1 ? 'multiple_currencies' : 'single_currency';
        $orderRows = (clone $orders)->selectRaw('COUNT(*) orders, COUNT(DISTINCT user_id) unique_buyers')->first();
        $settlements = $moneyStatus === 'single_currency'
            ? OrderSettlement::query()->where('status', OrderSettlement::STATUS_CONFIRMED)->whereIn('order_id', (clone $orders)->select('id'))->selectRaw('COALESCE(SUM(amount),0) amount, COUNT(DISTINCT order_id) orders')->first()
            : (object) ['amount' => null, 'orders' => 0];
        $refunds = $moneyStatus === 'single_currency'
            ? Refund::query()->where('status', 'completed')->whereIn('order_id', (clone $orders)->select('id'))->selectRaw('COALESCE(SUM(amount),0) amount')->first()
            : (object) ['amount' => null];
        $registrations = User::query()->where('created_at', '>=', $fromInstant)->where('created_at', '<', $toExclusiveInstant)->count();
        $granularity = $input['granularity'] ?? 'day';
        $localCreatedAt = $this->localCreatedAtExpression($timezone);
        $dateExpr = match ($granularity) {
            'month' => DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', {$localCreatedAt})" : "to_char({$localCreatedAt}, 'YYYY-MM')",
            'week' => DB::connection()->getDriverName() === 'sqlite' ? "date({$localCreatedAt}, '-' || ((strftime('%w', {$localCreatedAt}) + 6) % 7) || ' days')" : "to_char(date_trunc('week', {$localCreatedAt}), 'YYYY-MM-DD')",
            default => DB::connection()->getDriverName() === 'sqlite' ? "date({$localCreatedAt})" : "to_char({$localCreatedAt}, 'YYYY-MM-DD')",
        };
        $series = (clone $orders)->selectRaw("{$dateExpr} bucket, COUNT(*) orders")->when($moneyStatus === 'single_currency', fn ($query) => $query->selectRaw('COALESCE(SUM(amount_final),0) amount'), fn ($query) => $query->selectRaw('NULL amount'))->groupByRaw($dateExpr)->orderBy('bucket')->get()->map(function ($row): object {
            $row->orders = (int) $row->orders;
            $row->amount = $row->amount === null ? null : (int) $row->amount;
            return $row;
        });
        $currencyBreakdown = $currencyRows->mapWithKeys(fn ($row): array => [(string) $row->currency => ['orders' => (int) $row->orders, 'gross_amount' => (int) $row->gross_amount, 'discount_amount' => (int) $row->discount_amount]])->all();
        $singleCurrency = $currencyRows->count() === 1 ? (string) $currencyRows->first()->currency : ($input['currency'] ?? null);
        $money = fn (string $field): ?int => $moneyStatus === 'single_currency' ? (int) ($currencyRows->first()?->{$field} ?? 0) : null;
        $legacy = $this->legacySection($fromInstant, $toExclusiveInstant, $from, $to, $granularity, $timezone);
        return [
            'meta' => [
                'status' => 'available', 'report_version' => 'admin-reports-v1', 'source' => 'canonical_new_schema',
                'currency' => $singleCurrency ?? 'mixed', 'timezone' => $timezone,
                'filters' => ['from' => $from, 'to' => $to, 'granularity' => $granularity, 'status' => $input['status'] ?? null],
            ],
            'sections' => [
                'sales' => ['orders' => (int) $orderRows->orders, 'unique_buyers' => (int) $orderRows->unique_buyers, 'gross_amount' => $money('gross_amount'), 'discount_amount' => $money('discount_amount'), 'settled_amount' => $settlements->amount === null ? null : (int) $settlements->amount, 'refunded_amount' => $refunds->amount === null ? null : (int) $refunds->amount, 'money_status' => $moneyStatus, 'currency_breakdown' => (object) $currencyBreakdown],
                'global' => ['registrations' => $registrations, 'purchases' => ['count' => (int) $settlements->orders, 'amount' => $settlements->amount === null ? null : (int) $settlements->amount]],
                'lead_magnets' => ['status' => 'unavailable', 'source' => 'legacy_not_connected', 'data' => null],
                'legacy' => $legacy,
            ], 'series' => $series,
        ];
    }

    private function workspace(array $query): array
    {
        $input = validator($query, [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'granularity' => ['nullable', 'in:day,week,month'], 'source' => ['nullable', 'in:all,current,legacy'],
            'status' => ['nullable', 'in:all,paid,unpaid,refunded,cancelled'], 'currency' => ['nullable', 'string', 'size:3'],
            'q' => ['nullable', 'string', 'max:120'], 'payment_method' => ['nullable', 'string', 'max:80'],
            'channel' => ['nullable', 'string', 'max:120'], 'promocode' => ['nullable', 'string', 'max:120'],
            'export' => ['nullable', 'in:orders,periods,products,leads'],
            'order_customer' => ['nullable', 'string', 'max:200'], 'order_product' => ['nullable', 'string', 'max:200'],
            'order_status' => ['nullable', 'in:paid,unpaid,refunded,cancelled'],
            'order_sort' => ['nullable', 'in:created_at:asc,created_at:desc,amount:asc,amount:desc'],
            'page' => ['nullable', 'integer', 'min:1'], 'leads_page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $tz = (string) config('app.report_timezone', 'Europe/Moscow');
        $fromDate = CarbonImmutable::createFromFormat('Y-m-d', $input['from'] ?? now($tz)->startOfMonth()->toDateString(), $tz)->startOfDay();
        $toDate = CarbonImmutable::createFromFormat('Y-m-d', $input['to'] ?? now($tz)->toDateString(), $tz)->startOfDay();
        $fromUtc = $fromDate->setTimezone('UTC'); $toUtc = $toDate->addDay()->setTimezone('UTC');
        $source = $input['source'] ?? 'all'; $currency = strtoupper($input['currency'] ?? 'RUB');
        $granularity = $input['granularity'] ?? 'day'; $status = $input['status'] ?? 'all';
        $canonical = $this->canonicalQuery($fromUtc, $toUtc, $input, $currency, $status);
        $legacy = $this->legacyQuery($fromUtc, $toUtc, $input, $currency, $status);
        $useCurrent = in_array($source, ['all', 'current'], true); $useLegacy = in_array($source, ['all', 'legacy'], true);
        $summary = ['orders' => 0, 'buyers' => 0, 'items' => 0, 'sold_courses' => 0, 'order_amount' => 0, 'paid_orders' => 0, 'paid_amount' => 0, 'discount_amount' => 0, 'refunded_amount' => 0, 'registrations' => 0, 'lead_contacts' => 0];
        $periods = [];
        if ($useCurrent) { $this->mergeSummary($summary, $this->canonicalSummary($canonical)); $summary['registrations'] += User::query()->where('created_at', '>=', $fromUtc)->where('created_at', '<', $toUtc)->count(); $this->mergePeriods($periods, $this->canonicalPeriods($canonical, $granularity, $tz)); $this->mergePeriods($periods, $this->registrationPeriods($fromUtc, $toUtc, $granularity, $tz)); }
        if ($useLegacy) { $this->mergeSummary($summary, $this->legacySummary($legacy, $fromUtc, $toUtc)); $this->mergePeriods($periods, $this->legacyPeriods($legacy, $granularity, $tz)); }
        if ($granularity === 'day') { for ($cursor = $fromDate; $cursor->lessThanOrEqualTo($toDate); $cursor = $cursor->addDay()) { $periods[$cursor->toDateString()] ??= ['orders' => 0, 'amount' => 0, 'paid_orders' => 0, 'paid_amount' => 0, 'registrations' => 0]; } }
        ksort($periods); $periods = array_values(array_map(fn ($v, $k) => array_merge(['bucket' => $k], $v), $periods, array_keys($periods)));
        $comparisonFromDate = $fromDate->subYearNoOverflow(); $comparisonToDate = $toDate->subYearNoOverflow();
        $comparisonFromUtc = $comparisonFromDate->setTimezone('UTC'); $comparisonToUtc = $comparisonToDate->addDay()->setTimezone('UTC');
        $comparisonCurrent = $this->canonicalQuery($comparisonFromUtc, $comparisonToUtc, $input, $currency, $status);
        $comparisonLegacy = $this->legacyQuery($comparisonFromUtc, $comparisonToUtc, $input, $currency, $status);
        $comparisonSummary = ['orders' => 0, 'sold_courses' => 0, 'paid_orders' => 0, 'paid_amount' => 0];
        if ($useCurrent) $this->mergeComparison($comparisonSummary, $this->canonicalSummary($comparisonCurrent));
        if ($useLegacy) $this->mergeComparison($comparisonSummary, $this->legacySummary($comparisonLegacy, $comparisonFromUtc, $comparisonToUtc));
        $comparison = ['from' => $comparisonFromDate->toDateString(), 'to' => $comparisonToDate->toDateString(), ...$comparisonSummary, 'has_data' => $comparisonSummary['orders'] > 0];
        $rows = $this->workspaceOrders($canonical, $legacy, $useCurrent, $useLegacy, (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 25), $input);
        $breakdowns = $this->workspaceBreakdowns($canonical, $legacy, $useCurrent, $useLegacy);
        $leads = $useLegacy ? $this->workspaceLeads($legacy, $fromUtc, $toUtc, (int) ($input['leads_page'] ?? 1), (int) ($input['per_page'] ?? 25)) : ['rows' => [], 'page' => 1, 'per_page' => (int) ($input['per_page'] ?? 25), 'total' => 0];
        if (! empty($input['export'])) {
            $exportRows = match ($input['export']) {
                'orders' => $this->workspaceOrders($canonical, $legacy, $useCurrent, $useLegacy, 1, PHP_INT_MAX, $input)['rows'],
                'periods' => $periods,
                'products' => $breakdowns['products']['rows'],
                'leads' => $useLegacy ? $this->workspaceLeads($legacy, $fromUtc, $toUtc, 1, PHP_INT_MAX)['rows'] : [],
            };
            return ['export' => $input['export'], 'rows' => $exportRows];
        }
        $import = DB::table('legacy_user_import_runs')->where('status', 'completed')->orderByDesc('finished_at')->first();
        return ['meta' => ['status' => 'available', 'report_version' => 'admin-reports-v2', 'timezone' => $tz, 'from' => $fromDate->toDateString(), 'to' => $toDate->toDateString(), 'granularity' => $granularity, 'currency' => $currency, 'imported_at' => $import?->finished_at, 'money_basis' => 'current_provider_and_legacy_cart_ui', 'notes' => ['Выручка считается по оплаченным продажам; сравнение — с тем же периодом прошлого года.', 'Legacy paid statuses are historical payment states; legacy amounts use the imported cart total.'], 'filters' => ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString(), 'granularity' => $granularity, 'source' => $source, 'status' => $status, 'currency' => $currency, 'q' => $input['q'] ?? null]], 'summary' => $summary, 'comparison' => $comparison, 'periods' => $periods, 'orders' => $rows, 'breakdowns' => $breakdowns, 'leads' => $leads, 'options' => ['payment_methods' => [], 'channels' => [], 'promocodes' => [], 'currencies' => [$currency]]];
    }

    private function canonicalQuery(CarbonImmutable $from, CarbonImmutable $to, array $input, string $currency, string $status)
    { $q = Order::query()->where('orders.created_at', '>=', $from)->where('orders.created_at', '<', $to)->where('orders.currency', $currency); if (($input['q'] ?? '') !== '') $q->whereHas('user', fn ($u) => $u->where('email', 'like', '%'.$input['q'].'%')->orWhere('name', 'like', '%'.$input['q'].'%')); if ($status === 'paid') $q->where('orders.payment_status', 'paid'); elseif ($status === 'refunded') $q->where('orders.payment_status', 'refunded'); elseif ($status === 'cancelled') $q->where('orders.payment_status', 'cancelled'); elseif ($status === 'unpaid') $q->whereNotIn('orders.payment_status', ['paid','refunded','cancelled']); return $q; }
    private function legacyQuery(CarbonImmutable $from, CarbonImmutable $to, array $input, string $currency, string $status)
    { $q = DB::table('legacy_user_orders as o')->leftJoin('legacy_user_profiles as p','p.id','=','o.legacy_user_profile_id')->where('o.recorded_at', '>=', $from)->where('o.recorded_at', '<', $to)->where('o.currency', $currency); if (($input['q'] ?? '') !== '') $q->where(fn ($x) => $x->where('p.source_email','like','%'.$input['q'].'%')->orWhere('p.display_name','like','%'.$input['q'].'%')); if ($status === 'paid') $q->whereIn('o.source_status',[2,3]); elseif ($status === 'refunded') $q->where('o.source_status',6); elseif ($status === 'cancelled') $q->where('o.source_status',5); elseif ($status === 'unpaid') $q->whereNotIn('o.source_status',[2,3,5,6]); return $q; }
    private function canonicalSummary($q): array { $r=(clone $q)->selectRaw('COUNT(*) orders, COUNT(DISTINCT CASE WHEN payment_status IN (\'paid\',\'refunded\') THEN user_id END) buyers, COALESCE(SUM(amount_final),0) amount, COALESCE(SUM(amount_discount),0) discount, COALESCE(SUM(amount_refunded),0) refunded, SUM(CASE WHEN payment_status IN (\'paid\',\'refunded\') THEN 1 ELSE 0 END) paid_orders, COALESCE(SUM(CASE WHEN payment_status IN (\'paid\',\'refunded\') THEN amount_paid ELSE 0 END),0) paid_amount')->first(); $items=(int)(clone $q)->join('order_items','order_items.order_id','=','orders.id')->sum('order_items.quantity'); $paidItems=(int)(clone $q)->whereIn('orders.payment_status',['paid','refunded'])->join('order_items','order_items.order_id','=','orders.id')->sum('order_items.quantity'); return ['orders'=>(int)$r->orders,'buyers'=>(int)$r->buyers,'items'=>$items,'sold_courses'=>$paidItems,'order_amount'=>(int)$r->amount,'paid_orders'=>(int)$r->paid_orders,'paid_amount'=>(int)$r->paid_amount,'discount_amount'=>(int)$r->discount,'refunded_amount'=>(int)$r->refunded]; }
    private function legacySummary($q, $from, $to): array { $r=(clone $q)->selectRaw('COUNT(DISTINCT o.id) orders, COUNT(DISTINCT CASE WHEN o.source_status IN (2,3) THEN o.legacy_user_profile_id END) buyers, COALESCE(SUM(o.amount_minor),0) amount, SUM(CASE WHEN o.source_status IN (2,3) THEN 1 ELSE 0 END) paid_orders, COALESCE(SUM(CASE WHEN o.source_status IN (2,3) THEN o.amount_minor ELSE 0 END),0) paid_amount, COALESCE(SUM(CASE WHEN o.source_status = 6 THEN o.amount_minor ELSE 0 END),0) refunded_amount')->first(); $orderIds=(clone $q)->select('o.id'); $paidOrderIds=(clone $q)->whereIn('o.source_status',[2,3])->select('o.id'); $items=(int)DB::table('legacy_user_order_items')->whereIn('legacy_user_order_id',$orderIds)->sum('quantity'); $paidItems=(int)DB::table('legacy_user_order_items')->whereIn('legacy_user_order_id',$paidOrderIds)->sum('quantity'); $regs=(int)DB::table('legacy_user_profiles')->where('source_created_at','>=',$from)->where('source_created_at','<',$to)->count(); $contacts=(int)DB::table('legacy_user_records')->where('source_table','LeadMagnetContact')->where('recorded_at','>=',$from)->where('recorded_at','<',$to)->count(); return ['orders'=>(int)$r->orders,'buyers'=>(int)$r->buyers,'items'=>$items,'sold_courses'=>$paidItems,'order_amount'=>(int)$r->amount,'paid_orders'=>(int)$r->paid_orders,'paid_amount'=>(int)$r->paid_amount,'refunded_amount'=>(int)$r->refunded_amount,'registrations'=>$regs,'lead_contacts'=>$contacts]; }
    private function mergeSummary(array &$a,array $b): void { foreach($b as $k=>$v) $a[$k]=($a[$k]??0)+(int)$v; }
    private function mergeComparison(array &$a,array $b): void { foreach(['orders','sold_courses','paid_orders','paid_amount'] as $key) $a[$key]=($a[$key]??0)+(int)($b[$key]??0); }
    private function canonicalPeriods($q,string $granularity,string $tz): array { $expr=$this->workspaceBucket('orders.created_at',$granularity,$tz); return (clone $q)->selectRaw("$expr bucket, COUNT(*) orders, COALESCE(SUM(amount_final),0) amount, SUM(CASE WHEN payment_status IN ('paid','refunded') THEN 1 ELSE 0 END) paid_orders, COALESCE(SUM(CASE WHEN payment_status IN ('paid','refunded') THEN amount_paid ELSE 0 END),0) paid_amount")->groupByRaw($expr)->orderBy('bucket')->get()->mapWithKeys(fn($r)=>[(string)$r->bucket=>['orders'=>(int)$r->orders,'amount'=>(int)$r->amount,'paid_orders'=>(int)$r->paid_orders,'paid_amount'=>(int)$r->paid_amount,'registrations'=>0]])->all(); }
    private function legacyPeriods($q,string $granularity,string $tz): array { $expr=$this->workspaceBucket('o.recorded_at',$granularity,$tz); return (clone $q)->selectRaw("$expr bucket, COUNT(*) orders, COALESCE(SUM(o.amount_minor),0) amount, SUM(CASE WHEN o.source_status IN (2,3) THEN 1 ELSE 0 END) paid_orders, COALESCE(SUM(CASE WHEN o.source_status IN (2,3) THEN o.amount_minor ELSE 0 END),0) paid_amount")->groupByRaw($expr)->orderBy('bucket')->get()->mapWithKeys(fn($r)=>[(string)$r->bucket=>['orders'=>(int)$r->orders,'amount'=>(int)$r->amount,'paid_orders'=>(int)$r->paid_orders,'paid_amount'=>(int)$r->paid_amount,'registrations'=>0]])->all(); }
    private function registrationPeriods($from, $to, string $granularity, string $tz): array { $expr=$this->workspaceBucket('users.created_at',$granularity,$tz); return User::query()->where('users.created_at','>=',$from)->where('users.created_at','<',$to)->selectRaw("$expr bucket, COUNT(*) registrations")->groupByRaw($expr)->get()->mapWithKeys(fn($r)=>[(string)$r->bucket=>['orders'=>0,'amount'=>0,'paid_orders'=>0,'paid_amount'=>0,'registrations'=>(int)$r->registrations]])->all(); }
    private function mergePeriods(array &$a,array $b): void { foreach($b as $k=>$v){ foreach($v as $f=>$n) $a[$k][$f]=($a[$k][$f]??0)+(int)$n; } }
    private function workspaceBucket(string $column,string $granularity,string $tz): string { $driver=DB::connection()->getDriverName(); $local=$driver==='pgsql'?"$column AT TIME ZONE 'UTC' AT TIME ZONE '".str_replace("'","''",$tz)."'":($driver==='sqlite'?"datetime($column, '+3 hours')":$column); return $granularity==='month'?( $driver==='pgsql'?"to_char(date_trunc('month',$local),'YYYY-MM')":"strftime('%Y-%m',$local)"):( $granularity==='week'?( $driver==='pgsql'?"to_char(date_trunc('week',$local),'YYYY-MM-DD')":"date($local, '-' || ((strftime('%w',$local) + 6) % 7) || ' days')"):($driver==='pgsql'?"to_char($local,'YYYY-MM-DD')":"date($local)")); }
    private function workspaceOrders($current,$historical,bool $useCurrent,bool $useLegacy,int $page,int $perPage, array $controls = []): array
    {
        // Normalize both sources before filtering, sorting and paginating their union.
        $canonical = (clone $current)->leftJoin('users as report_user', 'report_user.id', '=', 'orders.user_id')
            ->selectRaw("CAST(orders.id AS TEXT) AS id, CAST(orders.id AS TEXT) AS number, 'current' AS source,
                orders.created_at, COALESCE(NULLIF(report_user.name, ''), NULLIF(report_user.email, ''), '—') AS customer, report_user.email AS customer_email,
                COALESCE((SELECT display_title FROM order_items WHERE order_id = orders.id ORDER BY id LIMIT 1), 'Курс') AS product,
                orders.amount_final AS amount, orders.payment_status AS status, NULL AS legacy_status,
                (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = orders.id) AS items, orders.id AS sort_id");
        if (! $useCurrent) {
            $canonical->whereRaw('1 = 0');
        }
        $legacy = (clone $historical)->selectRaw("'legacy-' || CAST(o.source_order_id AS TEXT) AS id,
            CAST(o.source_order_id AS TEXT) AS number, 'legacy' AS source, o.recorded_at AS created_at,
            COALESCE(NULLIF(p.display_name, ''), NULLIF(p.source_email, ''), '—') AS customer, p.source_email AS customer_email,
            COALESCE((SELECT title FROM legacy_user_order_items WHERE legacy_user_order_id = o.id ORDER BY id LIMIT 1), 'Курс') AS product,
            o.amount_minor AS amount,
            CASE WHEN o.source_status IN (2,3) THEN 'paid' WHEN o.source_status = 6 THEN 'refunded' WHEN o.source_status = 5 THEN 'cancelled' ELSE 'unpaid' END AS status,
            o.source_status AS legacy_status,
            (SELECT COALESCE(SUM(quantity), 0) FROM legacy_user_order_items WHERE legacy_user_order_id = o.id) AS items,
            o.source_order_id AS sort_id");
        if (! $useLegacy) {
            $legacy->whereRaw('1 = 0');
        }
        $query = DB::query()->fromSub($canonical->toBase()->unionAll($legacy), 'report_orders');
        \App\Support\Admin\ColumnQuery::search($query, ['customer', 'customer_email'], $controls['order_customer'] ?? null);
        \App\Support\Admin\ColumnQuery::search($query, ['product'], $controls['order_product'] ?? null);
        if (($controls['order_status'] ?? '') === 'unpaid') {
            $query->whereNotIn('status', ['paid', 'refunded', 'cancelled']);
        } elseif (filled($controls['order_status'] ?? null)) {
            $query->where('status', $controls['order_status']);
        }
        $total = (clone $query)->count();
        \App\Support\Admin\ColumnQuery::sort($query, $controls['order_sort'] ?? null, ['created_at' => 'created_at', 'amount' => 'amount'], 'created_at:desc', 'sort_id');
        $query->orderBy('source');
        if ($perPage !== PHP_INT_MAX) {
            $query->offset(($page - 1) * $perPage)->limit($perPage);
        }
        $rows = $query->get()->map(fn ($row) => [
            'id' => $row->id, 'number' => $row->number, 'source' => $row->source,
            'created_at' => $this->isoDate($row->created_at), 'customer' => $row->customer, 'product' => $row->product,
            'amount' => (int) $row->amount, 'status' => $row->status,
            'status_label' => $row->source === 'legacy' ? $this->legacyStatusLabel((int) $row->legacy_status) : $this->statusLabel($row->status),
            'items' => (int) $row->items,
        ])->all();

        return ['rows' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }
    private function workspaceBreakdowns($current,$historical,bool $useCurrent,bool $useLegacy): array
    {
        $empty = ['rows'=>[], 'page'=>1, 'per_page'=>100, 'total'=>0];
        $out=['products'=>$empty,'buyers'=>$empty,'channels'=>$empty,'promocodes'=>$empty,'payment_methods'=>$empty,'audience'=>$empty];
        $products=[]; if($useCurrent){ $paidCurrent=(clone $current)->whereIn('orders.payment_status',['paid','refunded']); foreach(DB::table('order_items as i')->join('orders as o','o.id','=','i.order_id')->whereIn('o.id',$paidCurrent->select('orders.id'))->selectRaw("COALESCE(i.display_title,'—') label, COUNT(DISTINCT o.id) orders, SUM(i.quantity) quantity, COALESCE(SUM(i.final_amount),0) amount")->groupBy('i.display_title')->get() as $r)$products[(string)$r->label]=['key'=>sha1((string)$r->label),'label'=>(string)$r->label,'orders'=>(int)$r->orders,'quantity'=>(int)$r->quantity,'amount'=>(int)$r->amount]; }
        if($useLegacy){ $paidLegacy=(clone $historical)->whereIn('o.source_status',[2,3]); foreach(DB::table('legacy_user_order_items as i')->join('legacy_user_orders as o','o.id','=','i.legacy_user_order_id')->whereIn('o.id',$paidLegacy->select('o.id'))->selectRaw("COALESCE(i.title,'—') label, COUNT(DISTINCT o.id) orders, SUM(i.quantity) quantity, COALESCE(SUM(i.cost_minor),0) amount")->groupBy('i.title')->get() as $r){$k=(string)$r->label; if(isset($products[$k])){$products[$k]['orders']+=(int)$r->orders;$products[$k]['quantity']+=(int)$r->quantity;$products[$k]['amount']+=(int)$r->amount;}else $products[$k]=['key'=>sha1($k),'label'=>$k,'orders'=>(int)$r->orders,'quantity'=>(int)$r->quantity,'amount'=>(int)$r->amount];} }
        $buyers=[];
        if ($useCurrent) foreach ((clone $current)->join('users as u','u.id','=','orders.user_id')->selectRaw("COALESCE(NULLIF(u.name,''),u.email,'—') label, COUNT(*) orders, COALESCE(SUM(orders.amount_final),0) amount")->groupBy('u.name','u.email')->get() as $r) { $k=(string)$r->label; $buyers[$k]=['key'=>sha1($k),'label'=>$k,'orders'=>(int)$r->orders,'quantity'=>0,'amount'=>(int)$r->amount]; }
        if ($useLegacy) foreach ((clone $historical)->selectRaw("COALESCE(NULLIF(p.display_name,''),p.source_email,'—') label, COUNT(DISTINCT o.id) orders, COALESCE(SUM(o.amount_minor),0) amount")->groupBy('p.display_name','p.source_email')->get() as $r) { $k=(string)$r->label; if(isset($buyers[$k])){$buyers[$k]['orders']+=(int)$r->orders;$buyers[$k]['amount']+=(int)$r->amount;} else $buyers[$k]=['key'=>sha1($k),'label'=>$k,'orders'=>(int)$r->orders,'quantity'=>0,'amount'=>(int)$r->amount]; }
        $out['products']['rows']=array_values($products); $out['products']['total']=count($products); $out['buyers']['rows']=array_values($buyers); $out['buyers']['total']=count($buyers); return $out;
    }
    private function workspaceLeads($legacy,$from,$to,int $page,int $perPage): array { $q=DB::table('legacy_user_records as r')->leftJoin('legacy_user_profiles as p','p.id','=','r.legacy_user_profile_id')->where('r.source_table','LeadMagnetContact')->where('r.recorded_at','>=',$from)->where('r.recorded_at','<',$to)->orderByDesc('r.recorded_at'); $total=(clone $q)->count(); $rows=$q->forPage($page,$perPage)->get()->map(fn($r)=>['id'=>(string)$r->id,'created_at'=>$this->isoDate($r->recorded_at),'customer'=>(string)($r->display_name ?: $r->source_email ?: '—'),'label'=>(string)($r->category ?: 'Lead magnet'),'messages'=>null])->all(); return ['rows'=>$rows,'page'=>$page,'per_page'=>$perPage,'total'=>$total]; }
    private function isoDate(mixed $value): ?string { if($value===null) return null; try { return CarbonImmutable::parse((string)$value)->toISOString(); } catch (\Throwable) { return (string)$value; } }
    private function statusLabel(string $s): string { return ['paid'=>'Оплачен','refunded'=>'Возврат','cancelled'=>'Отменён','pending_payment'=>'Неоплачен','pending'=>'Неоплачен','failed'=>'Ошибка оплаты','draft'=>'Черновик'][$s]??$s; }
    private function legacyStatus(int $s): string { return [2=>'paid',3=>'paid',6=>'refunded',5=>'cancelled'][ $s ]??'unpaid'; }
    private function legacyStatusLabel(int $s): string { return [2=>'Оплачен (legacy)',3=>'Выдан (legacy)',6=>'Возврат (legacy)',5=>'Отменён (legacy)'][$s]??'Неоплачен (legacy)'; }

    private function legacySection(CarbonImmutable $fromInstant, CarbonImmutable $toExclusiveInstant, string $from, string $to, string $granularity, string $timezone): array
    {
        $run = DB::table('legacy_user_import_runs')->where('status', 'completed')->orderByDesc('finished_at')->first();
        $filters = ['from' => $from, 'to' => $to, 'granularity' => $granularity];
        if ($run === null) {
            return [
                'status' => 'not_imported', 'source' => 'legacy_user_history', 'title' => 'Исторические данные legacy',
                'timezone' => $timezone, 'filters' => $filters,
                'orders' => ['count' => 0, 'unique_profiles' => 0, 'amount_minor' => 0, 'currency' => 'RUB', 'basis' => 'legacy_cart_ui_rub', 'by_status' => []],
                'items' => ['count' => 0, 'quantity' => 0], 'registrations' => 0,
                'lead_magnets' => ['status' => 'not_imported', 'contacts' => 0, 'purchase_metrics' => null],
            ];
        }

        $orders = DB::table('legacy_user_orders')
            ->where('recorded_at', '>=', $fromInstant)
            ->where('recorded_at', '<', $toExclusiveInstant);
        $orderTotals = (clone $orders)->selectRaw('COUNT(*) orders, COUNT(DISTINCT legacy_user_profile_id) unique_profiles, COALESCE(SUM(amount_minor), 0) amount_minor')->first();
        $statusLabels = ['0' => 'Создан', '1' => 'Неоплачен', '2' => 'Оплачен', '3' => 'Выдан', '4' => 'Создан новый', '5' => 'Отменён', '6' => 'Возврат'];
        $byStatus = (clone $orders)->selectRaw('source_status, COUNT(*) orders, COALESCE(SUM(amount_minor), 0) amount_minor')->groupBy('source_status')->orderBy('source_status')->get()->map(fn ($row): array => [
            'status' => (int) $row->source_status,
            'label' => $statusLabels[(string) $row->source_status] ?? 'Неизвестный статус',
            'count' => (int) $row->orders,
            'amount_minor' => (int) $row->amount_minor,
        ])->values()->all();
        $items = DB::table('legacy_user_order_items')->whereIn('legacy_user_order_id', (clone $orders)->select('id'))
            ->selectRaw('COUNT(*) item_count, COALESCE(SUM(quantity), 0) quantity')->first();
        $registrations = DB::table('legacy_user_profiles')->where('source_created_at', '>=', $fromInstant)->where('source_created_at', '<', $toExclusiveInstant)->count();
        $contacts = DB::table('legacy_user_records')->where('source_table', 'LeadMagnetContact')->where('category', 'communications')
            ->where('recorded_at', '>=', $fromInstant)->where('recorded_at', '<', $toExclusiveInstant)->count();
        $orderCount = (int) $orderTotals->orders;
        $status = $orderCount === 0 && $registrations === 0 && $contacts === 0 ? 'empty' : ($contacts > 0 ? 'partial' : 'available');

        return [
            'status' => $status, 'source' => 'legacy_user_history', 'title' => 'Исторические данные legacy',
            'timezone' => $timezone, 'filters' => $filters,
            'orders' => ['count' => $orderCount, 'unique_profiles' => (int) $orderTotals->unique_profiles, 'amount_minor' => (int) $orderTotals->amount_minor, 'currency' => 'RUB', 'basis' => 'legacy_cart_ui_rub', 'by_status' => $byStatus],
            'items' => ['count' => (int) $items->item_count, 'quantity' => (int) $items->quantity], 'registrations' => (int) $registrations,
            'lead_magnets' => ['status' => 'contacts_only', 'contacts' => $contacts, 'purchase_metrics' => null],
        ];
    }

    private function localCreatedAtExpression(string $timezone): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $quotedTimezone = str_replace("'", "''", $timezone);

            return "created_at AT TIME ZONE 'UTC' AT TIME ZONE '{$quotedTimezone}'";
        }

        if ($driver === 'sqlite' && $timezone === 'Europe/Moscow') {
            return "datetime(created_at, '+3 hours')";
        }

        return 'created_at';
    }
}
