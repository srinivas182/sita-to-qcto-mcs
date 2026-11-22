import { useMutation } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { useState } from 'react';
import { Alert, Button, Field } from '@/components/ui';
import { call, errorMessage } from '@/lib/api';
import { ApplyLayout } from './ApplyLayout';
import { save } from './session';

/** Continue an application on another device: reference + email, then a code. */
export function ResumePage({ reference: initial }: { reference?: string }) {
  const navigate = useNavigate();
  const [reference, setReference] = useState(initial ?? '');
  const [email, setEmail] = useState('');
  const [code, setCode] = useState('');
  const send = useMutation({ mutationFn: () => call('POST', '/applications/resume', { reference, email }) });
  const verify = useMutation({
    mutationFn: () =>
      call<{ id: string; token: string }>('POST', '/applications/resume/verify', { reference, email, code }),
    onSuccess: (r) => {
      save({ id: r.id, token: r.token });
      void navigate({ to: '/apply' });
    },
  });

  return (
    <ApplyLayout>
      <form
        className="grid gap-4"
        onSubmit={(e) => {
          e.preventDefault();
          if (send.isSuccess) verify.mutate();
          else send.mutate();
        }}
      >
        <div>
          <h1 className="text-page-title font-semibold tracking-tight">Continue your application</h1>
          <p className="mt-1 text-body text-muted">
            Enter the reference from your emails. We send a code to the address you applied with.
          </p>
        </div>
        <Field
          label="Reference"
          placeholder="APP-XXXXXXXX"
          required
          value={reference}
          onChange={(e) => setReference(e.target.value)}
        />
        <Field
          label="Email"
          type="email"
          autoComplete="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
        />
        {send.isSuccess ? (
          <>
            <Alert tone="info">If the reference and email match, a code is on its way.</Alert>
            <Field
              label="Code"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              required
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
              error={verify.error ? errorMessage(verify.error) : null}
            />
          </>
        ) : null}
        {send.error ? <Alert tone="danger">{errorMessage(send.error)}</Alert> : null}
        <Button type="submit" size="lg" block loading={send.isPending || verify.isPending}>
          {send.isSuccess ? 'Continue' : 'Send code'}
        </Button>
      </form>
    </ApplyLayout>
  );
}
