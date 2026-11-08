export function backendApiBaseUrl(): string {
  const configured = process.env.BACKEND_API_BASE_URL ?? process.env.NEXT_PUBLIC_API_BASE_URL;

  if (configured?.startsWith("http://") || configured?.startsWith("https://")) {
    return configured.replace(/\/+$/, "");
  }

  return "http://127.0.0.1:8000";
}

export function browserApiBaseUrl(): string {
  const configured = process.env.NEXT_PUBLIC_API_BASE_URL;
  if (configured?.startsWith("http://") || configured?.startsWith("https://") || configured?.startsWith("/")) {
    return configured.replace(/\/+$/, "");
  }
  return "/backend";
}
