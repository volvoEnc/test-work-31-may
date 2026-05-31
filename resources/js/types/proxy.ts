export type ProxyScheme = 'http' | 'https' | 'socks4' | 'socks5';

export type ProxyStatus = 'unknown' | 'checking' | 'online' | 'offline';

export type ProxyCheckSource = 'auto' | 'manual';

export interface ProxyServer {
  id: number;
  name: string | null;
  scheme: ProxyScheme;
  host: string;
  port: number;
  username: string | null;
  has_credentials: boolean;
  display_address: string;
  status: ProxyStatus;
  checking_started_at: string | null;
  last_checked_at: string | null;
  last_success_at: string | null;
  response_time_ms: number | null;
  failure_reason: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface ProxyCheck {
  id: number;
  source: ProxyCheckSource;
  status: 'online' | 'offline';
  started_at: string | null;
  finished_at: string | null;
  response_time_ms: number | null;
  http_status: number | null;
  error_code: string | null;
  error_message: string | null;
}

export interface PaginationMeta {
  current_page: number;
  per_page: number;
  total: number;
  last_page?: number;
  from?: number | null;
  to?: number | null;
}

export interface ProxyPayload {
  name?: string | null;
  scheme?: ProxyScheme;
  host?: string;
  port?: number;
  username?: string | null;
  password?: string | null;
}

export interface ProxyFilters {
  search: string;
  status: ProxyStatus | '';
  scheme: ProxyScheme | '';
  page: number;
  per_page: number;
  sort: 'created_at' | 'last_checked_at' | 'status' | 'host';
  direction: 'asc' | 'desc';
}
