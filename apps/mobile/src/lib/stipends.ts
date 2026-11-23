import { useQuery } from '@tanstack/react-query';
import { api, json } from './api';

export interface MyStipend {
  month: string;
  amount_cents: number;
  attendance_pct: number | null;
  status: 'paid' | 'pending' | 'held';
  paid_on: string | null;
  reason: string | null;
}

export interface MyBank {
  holder_name: string;
  bank: string;
  bank_name: string;
  branch_code: string;
  account: string;
  account_type: string;
}

export interface BankChoice {
  key: string;
  name: string;
  branch_code: string;
}

export const useMyStipends = () =>
  useQuery({ queryKey: ['my-stipends'], queryFn: () => json<{ data: MyStipend[]; bank: MyBank | null }>(api.GET('/me/stipends' as never)) });

export const useBanks = () =>
  useQuery({ queryKey: ['my-bank'], queryFn: () => json<{ data: MyBank | null; banks: BankChoice[] }>(api.GET('/me/bank-account' as never)) });

export const saveMyBank = (body: Record<string, string>) => json<{ data: MyBank }>(api.PUT('/me/bank-account' as never, { body } as never));

export interface Todo {
  bank_missing: boolean;
  eisa_to_sign: number;
  profile_problems: number;
}

/** What the learner can fix themselves, for one-tap cards on Home. */
export const useTodo = (enabled = true) => useQuery({ queryKey: ['my-todo'], queryFn: () => json<{ data: Todo }>(api.GET('/me/todo' as never)).then((r) => r.data), enabled });
