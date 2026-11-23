import type { Tone } from '@/components/ui';

export interface LearnerDocument {
  id: string;
  type: string;
  label: string;
  file_id: string;
  mime: string | null;
  certified: boolean;
  certified_on: string | null;
  certified_by: string | null;
  status: 'pending' | 'accepted' | 'rejected';
  reason: string | null;
  added_at: string;
  checks: {
    certification?: { ok: boolean; problem: string | null };
    id_match?: boolean;
    id_source?: string;
    quality?: { ok: boolean; problems: string[]; width: number; height: number };
  } | null;
}

export interface Requirement {
  type: string;
  label: string;
  state: 'missing' | 'pending' | 'accepted' | 'rejected' | 'expired';
  document_id: string | null;
  problem: string | null;
}

export interface DocumentRule {
  type: string;
  label: string;
  certified: boolean;
  max_age_days: number | null;
}

export const reqState: Record<Requirement['state'], { label: string; tone: Tone }> = {
  missing: { label: 'Missing', tone: 'danger' },
  pending: { label: 'To check', tone: 'warning' },
  accepted: { label: 'Accepted', tone: 'success' },
  rejected: { label: 'Rejected', tone: 'danger' },
  expired: { label: 'Certified too long ago', tone: 'danger' },
};

export const DOCUMENT_TYPES: Record<string, string> = {
  id_document: 'ID document or passport',
  highest_qualification: 'Highest school or other qualification',
  proof_of_address: 'Proof of address',
  birth_certificate: 'Birth certificate (learners under 18)',
  guardian_id: "Parent or guardian's ID (learners under 18)",
  cv: 'CV',
  other: 'Other',
};

/**
 * A quick check in the browser before uploading: is the photo big and sharp enough to
 * read? (The server measures it again and keeps the result.)
 */
export async function photoProblems(file: File): Promise<string[]> {
  if (!file.type.startsWith('image/')) return [];
  const bitmap = await createImageBitmap(file).catch(() => null);
  if (!bitmap) return ['We could not open this picture.'];
  const problems: string[] = [];
  if (Math.min(bitmap.width, bitmap.height) < 800)
    problems.push('The picture is small: it may be hard to read. Take it closer, or use the camera.');
  const w = Math.min(600, bitmap.width);
  const h = Math.max(3, Math.round((bitmap.height * w) / bitmap.width));
  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  if (!ctx) return problems;
  ctx.drawImage(bitmap, 0, 0, w, h);
  const px = ctx.getImageData(0, 0, w, h).data;
  const g = new Float32Array(w * h);
  for (let i = 0; i < w * h; i++) g[i] = 0.299 * px[i * 4]! + 0.587 * px[i * 4 + 1]! + 0.114 * px[i * 4 + 2]!;
  let n = 0;
  let mean = 0;
  let m2 = 0;
  for (let y = 1; y < h - 1; y++) {
    for (let x = 1; x < w - 1; x++) {
      const i = y * w + x;
      const lap = g[i - w]! + g[i + w]! + g[i - 1]! + g[i + 1]! - 4 * g[i]!;
      n++;
      const d = lap - mean;
      mean += d / n;
      m2 += d * (lap - mean);
    }
  }
  if (n > 1 && m2 / (n - 1) < 150) problems.push('The picture looks blurry. Hold the phone still and tap to focus.');
  return problems;
}
