/**
 * Which course materials to download or remove so the phone matches the provider's
 * current offline pack. Only changed versions are fetched again, to save data.
 */
export interface PackItem {
  id: string;
  version: number;
  size: number | null;
  low: boolean;
}

export interface Held {
  version: number;
  low: boolean;
  path: string;
}

export function planPack(items: PackItem[], held: Record<string, Held>): { download: PackItem[]; remove: string[]; bytes: number } {
  const download = items.filter((i) => !held[i.id] || held[i.id]!.version !== i.version || held[i.id]!.low !== i.low);
  const ids = new Set(items.map((i) => i.id));
  const remove = Object.keys(held).filter((id) => !ids.has(id));
  return { download, remove, bytes: download.reduce((n, i) => n + (i.size ?? 0), 0) };
}

export function megabytes(n: number): string {
  return n < 1_000_000 ? `${Math.max(1, Math.round(n / 1000))} KB` : `${(n / 1_000_000).toFixed(1)} MB`;
}
