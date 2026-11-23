import { useQuery } from '@tanstack/react-query';
import { api, json } from './api';

export interface Mentoring {
  is_mentor: boolean;
  workplaces: {
    id: string;
    name: string;
    employer: string | null;
    city: string | null;
    approval_state: string;
    cohorts: { name: string; qualification: string; status: string; learners: number; intake_on: string; planned_end_on: string }[];
  }[];
}

/** Workplaces the person mentors at, and cohorts planned there. */
export function useMentoring(enabled = true) {
  return useQuery({ queryKey: ['mentoring'], queryFn: () => json<{ data: Mentoring }>(api.GET('/me/mentoring' as never)).then((r) => r.data), enabled });
}
