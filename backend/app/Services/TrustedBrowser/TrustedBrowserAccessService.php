<?php

namespace App\Services\TrustedBrowser;

use App\Models\User;
use Illuminate\Http\Request;

final class TrustedBrowserAccessService
{
    public function __construct(private readonly TrustedBrowserService $trustedBrowsers) {}

    public function evaluate(Request $request, User $user): TrustedBrowserAccessDecision
    {
        $context = $this->trustedBrowsers->resolveState($request, $user);
        $mode = $this->trustedBrowsers->mode();
        [$code, $message] = $this->errorForState($context['state']);
        $decision = new TrustedBrowserAccessDecision(
            mode: $mode,
            state: $context['state'],
            code: $code,
            message: $message,
        );

        $this->trustedBrowsers->auditObservedDecision($request, $user, $decision);

        return $decision;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function errorForState(string $state): array
    {
        return match ($state) {
            'trusted' => [null, null],
            'unknown_can_confirm' => [
                'TRUSTED_BROWSER_REQUIRED',
                'Подтвердите этот браузер для доступа к защищённым материалам.',
            ],
            'unknown_support_required', 'revoked_current' => [
                'TRUSTED_BROWSER_SUPPORT_REQUIRED',
                'Для восстановления доступа обратитесь в поддержку.',
            ],
            'risk_step_up_required' => [
                'TRUSTED_BROWSER_RISK_LOCKED',
                'Доступ временно ограничен. Требуется дополнительная проверка.',
            ],
            default => [
                'TRUSTED_BROWSER_REQUIRED',
                'Подтвердите этот браузер для доступа к защищённым материалам.',
            ],
        };
    }
}
