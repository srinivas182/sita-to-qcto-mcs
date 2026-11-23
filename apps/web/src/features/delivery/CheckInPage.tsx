import { useMutation } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { CheckCircle2, MapPin } from 'lucide-react';
import { useState } from 'react';
import { Alert, Button, Card, PageHeader } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import { clockOf } from './TimetablePage';
import type { AttendanceMark } from './types';

type Result = AttendanceMark & { action: string; session: { title: string | null; starts_at: string } };

/**
 * Checking in from the phone's browser, for learners without the app: the code from the
 * facilitator's screen opens this page, which asks for the phone's location once.
 */
export function CheckInPage() {
  const { token } = useParams({ from: '/app/c/$token' });
  const [error, setError] = useState<string | null>(null);
  const send = useMutation({
    mutationFn: (place: { latitude?: number; longitude?: number; accuracy?: number }) =>
      call<{ data: Result }>('POST', '/me/check-ins', { token, ...place }).then((r) => r.data),
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  const start = () => {
    setError(null);
    if (!('geolocation' in navigator)) {
      send.mutate({});
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (p) => send.mutate({ latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy: p.coords.accuracy }),
      () => send.mutate({}),
      { enableHighAccuracy: true, timeout: 15_000, maximumAge: 30_000 },
    );
  };
  const r = send.data;

  return (
    <>
      <PageHeader title="Check in to class" />
      <Card>
        {r ? (
          <div className="flex items-start gap-3">
            <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-success" aria-hidden />
            <div>
              <h2 className="font-semibold text-ink">
                {r.action === 'checked_out'
                  ? 'Checked out'
                  : r.action === 'checked_in'
                    ? 'You are checked in'
                    : 'You were already checked in'}
              </h2>
              <p className="text-small text-muted">
                {r.session.title} · {clockOf(r.session.starts_at)}
                {r.status === 'late' ? ` · ${r.minutes_late} minutes late` : ''}
              </p>
              {r.needs_review ? (
                <p className="mt-1 text-small text-warning">{r.review_reason}. Your facilitator will confirm it.</p>
              ) : null}
              <Link to="/" className="mt-3 inline-block text-small text-primary-700 underline">
                Done
              </Link>
            </div>
          </div>
        ) : (
          <div className="grid gap-3">
            <p className="flex items-start gap-2 text-small text-muted">
              <MapPin className="mt-0.5 size-4 shrink-0" aria-hidden />
              Your phone's location is checked against the centre. Only the distance is kept, never where you are.
            </p>
            {error ? <Alert tone="danger">{error}</Alert> : null}
            <Button loading={send.isPending} onClick={start}>
              Check in now
            </Button>
          </div>
        )}
      </Card>
    </>
  );
}
