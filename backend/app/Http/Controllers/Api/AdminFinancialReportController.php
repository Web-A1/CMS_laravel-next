<?php

namespace App\Http\Controllers\Api;

use App\Services\AdminFinancialReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminFinancialReportController
{
    public function __invoke(Request $request, AdminFinancialReportService $reports): JsonResponse|StreamedResponse
    {
        $report = $reports->report($request->query());
        if (isset($report['export'])) {
            return $this->csvResponse($report['export'], $report['rows']);
        }

        return response()->json($report, 200, ['Cache-Control' => 'private, no-store']);
    }

    private function csvResponse(string $kind, array $rows): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void { $out=fopen('php://output','wb'); if(!$rows){ fputcsv($out,['Нет данных']); fclose($out); return; } fputcsv($out,array_keys((array)$rows[0])); foreach($rows as $row) fputcsv($out,array_map(fn($v)=>$this->csvCell($v),array_values((array)$row))); fclose($out); }, 'report-'.$kind.'.csv', ['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'private, no-store']);
    }
    private function csvCell(mixed $value): string { $text=is_scalar($value)||(is_null($value))?(string)$value:json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); return preg_match('/^[=+\-@]/',$text)?"'".$text:$text; }
}
