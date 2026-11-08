export function GET(): Response {
  return new Response(null, { status: 301, headers: { Location: "/" } });
}
