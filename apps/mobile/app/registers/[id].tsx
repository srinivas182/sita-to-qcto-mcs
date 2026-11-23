import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, api, errorMessage, json } from '@/lib/api';
import { useApp } from '@/lib/app';
import { useMyRegisters } from '@/lib/delivery';

interface Register {
  month: string;
  cohort: string | null;
  grid: { sessions: { id: string; date: string; start: string; module: string | null; kind: string }[]; learners: { user_id: string; marks: Record<string, string | null>; held: number; attended: number; excused: number; pct: number | null }[] };
}
const LABEL: Record<string, string> = { present: 'Present', late: 'Late', left_early: 'Left early', absent: 'Absent', excused: 'Excused' };

/** Check my month and sign it, or say what is wrong. */
export default function RegisterScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, online } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: ['register', id], queryFn: () => json<{ data: Register }>(api.GET(`/registers/${id}` as never)).then((r) => r.data), enabled: online });
  const mine = (useMyRegisters(online).data ?? []).find((r) => r.id === id);
  const [name, setName] = useState('');
  const [reason, setReason] = useState('');
  const [disputing, setDisputing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const act = async (path: string, body: object) => {
    setBusy(true);
    setError(null);
    try {
      await json(api.POST(path as never, { body, headers: { 'X-Client': 'mobile' } } as never));
      void qc.invalidateQueries({ queryKey: ['my-registers'] });
      void qc.invalidateQueries({ queryKey: ['dashboard'] });
      router.back();
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const line = q.data?.grid.learners[0];

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" keyboardShouldPersistTaps="handled">
        <BackHeader />
        <Title>{t('registers.month', { month: q.data?.month ?? '' })}</Title>
        {line && q.data ? (
          <Card className="gap-2">
            <Body muted={false}>{t('registers.line', { attended: line.attended, held: line.held, pct: line.pct ?? 0 })}</Body>
            {q.data.grid.sessions.map((s) => (
              <View key={s.id} className="flex-row justify-between border-b border-line py-1.5">
                <Text className="font-sans text-small text-ink">{s.date} {s.start} {s.module ?? ''}</Text>
                <Text className={`font-medium text-small ${line.marks[s.id] === 'absent' ? 'text-danger' : 'text-ink'}`}>{line.marks[s.id] ? LABEL[line.marks[s.id]!] : '–'}</Text>
              </View>
            ))}
          </Card>
        ) : null}
        {error ? <Notice tone="danger">{error}</Notice> : null}
        {mine && mine.my_status === 'pending' ? (
          disputing ? (
            <Card className="gap-3">
              <Field label={t('registers.wrong')} value={reason} onChangeText={setReason} />
              <Button title={t('registers.send')} variant="danger" loading={busy} disabled={reason.trim().length < 5} onPress={() => void act(`/registers/${id}/dispute`, { reason })} />
              <Button title={t('common.back')} variant="ghost" onPress={() => setDisputing(false)} />
            </Card>
          ) : (
            <Card className="gap-3">
              <Body>{mine.role === 'facilitator' ? t('registers.confirmFacilitator') : t('registers.confirm')}</Body>
              <Field label={t('registers.name')} autoComplete="name" value={name} onChangeText={setName} />
              <Button title={t('registers.sign')} loading={busy} disabled={!name.trim()} onPress={() => void act(`/registers/${id}/sign`, { name, role: mine.role })} />
              {mine.role === 'learner' ? <Button title={t('registers.dispute')} variant="ghost" onPress={() => setDisputing(true)} /> : null}
            </Card>
          )
        ) : mine ? (
          <Notice tone={mine.my_status === 'signed' ? 'success' : 'warning'}>{t(`registers.${mine.my_status}` as never)}</Notice>
        ) : null}
      </ScrollView>
    </Screen>
  );
}
