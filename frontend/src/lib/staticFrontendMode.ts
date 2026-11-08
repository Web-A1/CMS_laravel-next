export function isStaticFrontendModeEnabled(): boolean {
  return process.env.FRONTEND_STATIC_MODE === "1";
}
