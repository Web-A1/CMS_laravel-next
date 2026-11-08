type LessonNavArrowIconProps = {
  readonly className?: string;
  readonly direction: "previous" | "next";
  readonly variant?: "arrow" | "chevron";
};

export function LessonNavArrowIcon({ className = "", direction, variant = "arrow" }: LessonNavArrowIconProps) {
  const points = variant === "chevron"
    ? direction === "previous" ? "8 2 4 6 8 10" : "4 2 8 6 4 10"
    : direction === "previous" ? "13 8 5 12 13 16" : "51 8 59 12 51 16";
  const motionClass = direction === "previous" ? "group-hover:-translate-x-0.5" : "group-hover:translate-x-0.5";
  const sizeClass = variant === "chevron" ? "h-3 w-3 shrink-0" : "h-6 w-10 sm:w-14";

  return (
    <svg
      aria-hidden="true"
      className={`${className} ${sizeClass} overflow-visible transition-transform duration-150 ease-out ${motionClass}`}
      fill="none"
      viewBox={variant === "chevron" ? "0 0 12 12" : "0 0 64 24"}
      xmlns="http://www.w3.org/2000/svg"
    >
      {variant === "arrow" ? <path d="M5 12H59" stroke="currentColor" strokeLinecap="round" strokeWidth="1.25" /> : null}
      <polyline points={points} stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.25" />
    </svg>
  );
}
