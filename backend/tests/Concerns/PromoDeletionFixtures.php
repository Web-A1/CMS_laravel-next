<?php

namespace Tests\Concerns;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\Subject;
use App\Models\User;
use App\Services\AdminOperationsService;
use App\Services\OrderItemSnapshotService;
use Illuminate\Support\Str;

trait PromoDeletionFixtures
{
    private function promoOrder(): Order
    {
        $user = User::factory()->create();
        $subject = Subject::create(['slug' => 'promo-'.Str::uuid(), 'title' => 'Subject', 'sort_order' => 1, 'is_active' => true]);
        $course = Course::create(['subject_id' => $subject->id, 'slug' => 'promo-'.Str::uuid(), 'title' => 'Course',
            'short_title' => 'Course', 'price_cents' => 10000, 'sort_order' => 1, 'is_active' => true]);
        Lesson::create(['course_id' => $course->id, 'slug' => 'lesson', 'title' => 'Lesson', 'sort_order' => 1, 'is_active' => true]);
        $order = Order::create(['user_id' => $user->id, 'course_id' => $course->id, 'payment_status' => 'draft',
            'access_status' => 'not_granted', 'currency' => 'RUB', 'amount_total' => 10000, 'amount_discount' => 0,
            'amount_final' => 10000, 'amount_paid' => 0, 'amount_refunded' => 0]);
        app(OrderItemSnapshotService::class)->createCourseItem($order, $course);

        return $order->fresh();
    }

    private function ownedPromo(User $owner, string $code = 'OWN-PROMO'): PromoCode
    {
        $result = app(AdminOperationsService::class)->promos($owner, 'create', [
            'code' => $code, 'status' => 'active', 'discount_kind' => 'fixed_minor', 'discount_minor' => 1500,
            'expires_at' => now()->addDay()->toISOString(), 'activation_limit' => 10, 'per_user_limit' => 2,
        ], key: 'create:'.$code);

        return PromoCode::findOrFail($result['data']['id']);
    }
}
