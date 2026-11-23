import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Alert, Button, Dialog, Field, Select } from '@/components/ui';
import { ApiError, call, errorMessage } from '@/lib/api';
import type { Bank, BankChoice } from './stipends';

/** A learner's bank details for stipends, entered by the provider. Only the last 4 digits are ever shown. */
export function BankDialog({
  enrolmentId,
  name,
  agreementId,
  onClose,
}: {
  enrolmentId: string;
  name: string | null;
  agreementId: string;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const q = useQuery({
    queryKey: ['bank', enrolmentId],
    queryFn: () => call<{ data: Bank | null; banks: BankChoice[] }>('GET', `/enrolments/${enrolmentId}/bank-account`),
  });
  const [form, setForm] = useState({
    holder_name: '',
    bank: '',
    branch_code: '',
    account_number: '',
    account_type: 'savings',
  });
  const [error, setError] = useState<string | null>(null);
  const save = useMutation({
    mutationFn: () => call('PUT', `/enrolments/${enrolmentId}/bank-account`, form),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['grant', agreementId] });
      void qc.invalidateQueries({ queryKey: ['bank', enrolmentId] });
      onClose();
    },
    onError: (e) => setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e)),
  });
  const current = q.data?.data;
  return (
    <Dialog
      open
      onOpenChange={(o) => !o && onClose()}
      title={`Bank details: ${name ?? 'learner'}`}
      description={
        current
          ? `On file: ${current.bank_name} ${current.account} (${current.holder_name}). Enter new details to replace them.`
          : 'Learners can also add these in the app.'
      }
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button loading={save.isPending} onClick={() => save.mutate()}>
            Save
          </Button>
        </>
      }
    >
      <div className="grid gap-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Field
          label="Account holder"
          value={form.holder_name}
          onChange={(e) => setForm({ ...form, holder_name: e.target.value })}
          autoComplete="off"
        />
        <div className="grid gap-3 sm:grid-cols-2">
          <Select
            label="Bank"
            value={form.bank}
            onChange={(e) =>
              setForm({
                ...form,
                bank: e.target.value,
                branch_code: q.data?.banks.find((b) => b.key === e.target.value)?.branch_code ?? '',
              })
            }
          >
            <option value="">Choose…</option>
            {q.data?.banks.map((b) => (
              <option key={b.key} value={b.key}>
                {b.name}
              </option>
            ))}
          </Select>
          <Field
            label="Branch code"
            inputMode="numeric"
            maxLength={6}
            value={form.branch_code}
            onChange={(e) => setForm({ ...form, branch_code: e.target.value })}
          />
          <Field
            label="Account number"
            inputMode="numeric"
            autoComplete="off"
            value={form.account_number}
            onChange={(e) => setForm({ ...form, account_number: e.target.value.replace(/\s/g, '') })}
          />
          <Select
            label="Account type"
            value={form.account_type}
            onChange={(e) => setForm({ ...form, account_type: e.target.value })}
          >
            <option value="savings">Savings</option>
            <option value="cheque">Cheque or current</option>
            <option value="transmission">Transmission</option>
          </Select>
        </div>
      </div>
    </Dialog>
  );
}
