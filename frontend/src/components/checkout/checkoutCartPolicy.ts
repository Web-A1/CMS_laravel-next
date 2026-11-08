export const MULTI_COURSE_CHECKOUT_MESSAGE =
  "Сейчас можно оплатить только один товар за раз. Оставьте в корзине один товар, чтобы продолжить.";

export function multiCourseCheckoutMessage(courseCount: number): string {
  return courseCount > 1 ? MULTI_COURSE_CHECKOUT_MESSAGE : "";
}
