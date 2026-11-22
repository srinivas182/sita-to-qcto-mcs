import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Alert, Button, Dialog } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';

interface Row {
  line: number;
  name: string;
  contact: string;
  role: string | null;
  centre: string | null;
  ok: boolean;
  problems: string[];
}
interface Result {
  rows: Row[];
  ready: number;
  problems: number;
  sent: number;
}

const TEMPLATE = 'first_name,last_name,email,phone,role,centre,id_number\n';

/** Invite many people from a spreadsheet: check every row first, then send. */
export function ImportDialog({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [csv, setCsv] = useState(TEMPLATE);
  const [result, setResult] = useState<Result | null>(null);
  const run = useMutation({
    mutationFn: (send: boolean) => call<Result>('POST', '/people/import', { csv, send }),
    onSuccess: (r) => {
      setResult(r);
      if (r.sent) void qc.invalidateQueries({ queryKey: ['users'] });
    },
  });

  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title="Import people"
      description="Paste rows from a spreadsheet, or open a CSV file. Each row is checked; invitations go out only when every row is right."
    >
      <div className="grid gap-3">
        {result?.sent ? (
          <Alert tone="success">{result.sent} invitations sent. People appear in the list once they accept.</Alert>
        ) : (
          <>
            <label className="grid gap-1.5 text-small">
              <span className="font-medium">Rows (header first)</span>
              <textarea
                className="min-h-36 rounded-lg border border-line bg-surface p-2 font-mono text-small"
                value={csv}
                onChange={(e) => {
                  setCsv(e.target.value);
                  setResult(null);
                }}
              />
            </label>
            <label className="text-small">
              <span className="sr-only">CSV file</span>
              <input
                type="file"
                accept=".csv,text/csv"
                onChange={(e) => {
                  const file = e.target.files?.[0];
                  if (file) void file.text().then((t) => (setCsv(t), setResult(null)));
                }}
              />
            </label>
            <p className="text-small text-muted">
              Roles by name (Learner, Assessor…). Centre by name for roles that belong to one centre. ID numbers are
              checked.
            </p>
          </>
        )}
        {result && !result.sent ? (
          <div className="grid gap-2">
            <p className="text-small">
              <strong>{result.ready}</strong> ready{result.problems ? `, ${result.problems} to fix` : ''}.
            </p>
            <ul className="grid max-h-64 gap-1 overflow-auto rounded-lg border border-line p-2 text-small">
              {result.rows.map((r) => (
                <li key={r.line} className="flex items-start gap-2">
                  {r.ok ? (
                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-success" aria-label="Ready" />
                  ) : (
                    <XCircle className="mt-0.5 size-4 shrink-0 text-danger" aria-label="To fix" />
                  )}
                  <span>
                    <strong>Line {r.line}</strong> {r.name} · {r.contact} {r.role ? `· ${r.role}` : ''}{' '}
                    {r.centre ? `· ${r.centre}` : ''}
                    {r.problems.length ? <span className="block text-danger">{r.problems.join(' ')}</span> : null}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
        {run.error ? <Alert tone="danger">{errorMessage(run.error)}</Alert> : null}
        <div className="flex justify-end gap-2">
          <Button variant="secondary" onClick={onClose}>
            {result?.sent ? 'Close' : 'Cancel'}
          </Button>
          {result?.sent ? null : result && result.problems === 0 && result.ready > 0 ? (
            <Button loading={run.isPending} onClick={() => run.mutate(true)}>
              Send {result.ready} invitations
            </Button>
          ) : (
            <Button loading={run.isPending} disabled={csv.trim() === TEMPLATE.trim()} onClick={() => run.mutate(false)}>
              Check
            </Button>
          )}
        </div>
      </div>
    </Dialog>
  );
}
