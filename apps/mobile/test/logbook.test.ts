import { createHash } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { entryHash, entryHashInput, localIso } from '../src/core/entryHash';
import { chainHead, processQueue, type QueuedEntry } from '../src/core/logbookQueue';

const sha = (s: string) => createHash('sha256').update(s).digest('hex');

describe('entry hash', () => {
  it('matches the server (shared test vector)', async () => {
    const h = await entryHash(
      {
        prev: null,
        clientId: '6f1c7a0e-9b1e-4f1e-8d63-0a1b2c3d4e5f',
        moduleId: '01jdemomodule0000000000000',
        activity: 'mix',
        date: '2027-02-03',
        minutes: 240,
        note: 'Mixed mortar | laid 2 courses',
        photoHashes: ['AB12'],
        deviceTime: '2027-02-03T14:05:00+02:00',
      },
      sha,
    );
    expect(h).toBe('8f29e3c78e81e054144028305cab0f8f7debad2ee11c94f239552d49188f722c');
  });

  it('normalises the note and photo order like the server', () => {
    const base = { prev: 'p', clientId: 'A', moduleId: 'B', activity: 'x', date: '2027-01-01', minutes: 60, photoHashes: ['b', 'A'], deviceTime: 't' };
    expect(entryHashInput({ ...base, note: '  line one\r\nline two \n' })).toBe('v1|p|a|b|x|2027-01-01|60|line one\nline two|a,b|t');
    expect(entryHashInput({ ...base, note: null, photoHashes: [] })).toBe('v1|p|a|b|x|2027-01-01|60|||t');
  });

  it('writes the local time with its offset', () => {
    expect(localIso(new Date(2027, 1, 3, 14, 5, 9))).toMatch(/^2027-02-03T14:05:09[+-]\d{2}:\d{2}$/);
  });
});

const entry = (id: string, at: number, photos = 0): QueuedEntry => ({
  clientId: id,
  path: '/me/logbook',
  body: { client_id: id, hash: `h-${id}` },
  photos: Array.from({ length: photos }, (_, n) => ({ uri: `file://${id}-${n}.jpg`, hash: `ph-${n}` })),
  createdAt: at,
  status: 'pending',
  error: null,
});

describe('logbook queue', () => {
  it('sends in order, uploading photos first, and keeps conflicts for the learner', async () => {
    const posted: string[] = [];
    const r = await processQueue([entry('b', 2), entry('a', 1, 2), entry('c', 3)], {
      upload: async (p) => `file-${p.uri}`,
      post: async (_path, body) => {
        posted.push(String(body.client_id));
        if (body.client_id === 'b') return { status: 422, body: { errors: { work_date: ['You were not placed at a workplace on that day.'] } } };
        if (body.client_id === 'a') expect(body.file_ids).toEqual(['file-file://a-0.jpg', 'file-file://a-1.jpg']);
        return { status: 201 };
      },
    });
    expect(posted).toEqual(['a', 'b', 'c']);
    expect(r).toMatchObject({ sent: 2, conflicts: 1, stoppedOnNetwork: false });
    expect(r.queue).toHaveLength(1);
    expect(r.queue[0]).toMatchObject({ clientId: 'b', status: 'conflict', error: 'You were not placed at a workplace on that day.' });
  });

  it('stops on a network failure and keeps uploaded photo ids for the retry', async () => {
    let uploads = 0;
    const deps = { upload: async () => `f${++uploads}`, post: async () => ({ status: 0 }) };
    const first = await processQueue([entry('a', 1, 1), entry('b', 2)], deps);
    expect(first).toMatchObject({ sent: 0, stoppedOnNetwork: true });
    expect(first.queue.find((q) => q.clientId === 'a')!.photos[0]!.fileId).toBe('f1');
    const second = await processQueue(first.queue, { ...deps, post: async () => ({ status: 201 }) });
    expect(second.sent).toBe(2);
    expect(uploads).toBe(1);
  });

  it('chains a new entry onto the newest waiting one, else the server\'s last', () => {
    expect(chainHead([], 'server')).toBe('server');
    expect(chainHead([entry('a', 1), entry('b', 2)], 'server')).toBe('h-b');
    expect(chainHead([{ ...entry('a', 1), status: 'conflict' }], 'server')).toBe('server');
  });
});
