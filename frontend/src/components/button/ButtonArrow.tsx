type ButtonArrowProps = { direction?: "right" | "left" | "up" | "down"; download?: boolean; chevron?: boolean };
const arrows = { left: "M19 12H5m6-6-6 6 6 6", up: "M12 19V5m-6 6 6-6 6 6", down: "M12 5v14m-6-6 6 6 6-6", right: "M5 12h14m-6-6 6 6-6 6" };
const chevrons = { left: "M14 6l-6 6 6 6", up: "M6 14l6-6 6 6", down: "M6 10l6 6 6-6", right: "M10 6l6 6-6 6" };

export default function ButtonArrow({ direction = "right", download = false, chevron = false }: ButtonArrowProps) {
  const axis = download ? "down" : direction;
  return <span className="button-arrow" data-direction={axis} aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" focusable="false">
      <g className="button-arrow__motion"><path d={chevron && !download ? chevrons[axis] : arrows[axis]} /></g>
      {download ? <path d="M4 17v4h16v-4" /> : null}
    </svg>
  </span>;
}
