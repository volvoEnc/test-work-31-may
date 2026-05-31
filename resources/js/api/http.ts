type FieldErrors = Record<string, string[]>;

interface ErrorPayload {
  message?: string;
  errors?: FieldErrors;
}

export class ApiError extends Error {
  status: number;
  errors: FieldErrors;

  constructor(message: string, status: number, errors: FieldErrors = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

export const baseUrl = import.meta.env.VITE_API_BASE_URL ?? '/api/v1';

function makeUrl(path: string): string {
  if (/^https?:\/\//i.test(path)) {
    return path;
  }

  return `${baseUrl.replace(/\/$/, '')}/${path.replace(/^\//, '')}`;
}

async function parseJson(response: Response, fallbackOnMalformed = false): Promise<unknown> {
  const text = await response.text();

  if (!text) {
    return undefined;
  }

  try {
    return JSON.parse(text);
  } catch {
    if (fallbackOnMalformed) {
      return {};
    }

    throw new Error('Unable to parse JSON response.');
  }
}

export async function requestJson<T>(path: string, options: RequestInit = {}): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set('Accept', 'application/json');

  if (options.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json');
  }

  const response = await fetch(makeUrl(path), {
    ...options,
    headers,
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const payload = await parseJson(response, !response.ok);

  if (!response.ok) {
    const errorPayload = (payload ?? {}) as ErrorPayload;
    throw new ApiError(
      errorPayload.message ?? `Request failed with status ${response.status}`,
      response.status,
      errorPayload.errors ?? {},
    );
  }

  return payload as T;
}
