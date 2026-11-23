import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, api, errorMessage, json } from '@/lib/api';
import { useApp } from '@/lib/app';
import { useOpenIntakes, type Question } from '@/lib/enrolment';
import { outbox } from '@/lib/outbox';

/**
 * Apply for one intake. Online it is sent at once (so a missing ID number can be fixed
 * here); offline it is kept in the outbox and sent when the phone is back online.
 */
export default function ApplyTo() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, online, syncNow } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const intake = (useOpenIntakes(online).data ?? []).find((i) => i.id === id);
  const [answers, setAnswers] = useState<Record<string, string | boolean>>({});
  const [idNumber, setIdNumber] = useState('');
  const [needsId, setNeedsId] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [queued, setQueued] = useState(false);

  const submit = useMutation({
    mutationFn: async () => {
      if (!online) {
        await outbox.enqueue({ stream: 'applications', method: 'POST', path: '/me/applications', body: { intake_id: id, answers } });
        return 'queued' as const;
      }
      if (needsId) {
        await api.PATCH('/me/profile' as never, { body: { id_type: 'sa_id', id_number: idNumber } } as never);
      }
      await json(api.POST('/me/applications' as never, { body: { intake_id: id, answers } } as never));
      return 'sent' as const;
    },
    onSuccess: (how) => {
      if (how === 'queued') {
        setQueued(true);
        return;
      }
      void qc.invalidateQueries({ queryKey: ['my-applications'] });
      void syncNow();
      router.replace('/documents' as never);
    },
    onError: (e) => {
      const fields = e instanceof ApiError ? e.fields : { status: [errorMessage(e)] };
      if (fields.id_number) setNeedsId(true);
      setErrors(fields);
    },
  });

  const answer = (q: Question) => {
    if (q.type === 'yesno') {
      return (
        <View key={q.key} className="gap-1.5">
          <Text className="font-medium text-small text-ink dark:text-night-ink">{q.label}</Text>
          <View className="flex-row gap-2">
            {[true, false].map((v) => (
              <Pressable key={String(v)} accessibilityRole="radio" accessibilityState={{ checked: answers[q.key] === v }} onPress={() => setAnswers({ ...answers, [q.key]: v })}
                className={`h-11 flex-1 items-center justify-center rounded-xl border ${answers[q.key] === v ? 'border-2 border-primary-600 bg-primary-50' : 'border-line'}`}>
                <Text className="font-medium text-body text-ink">{v ? t('common.yes') : t('common.no')}</Text>
              </Pressable>
            ))}
          </View>
        </View>
      );
    }
    if (q.type === 'choice') {
      return (
        <View key={q.key} className="gap-1.5">
          <Text className="font-medium text-small text-ink dark:text-night-ink">{q.label}</Text>
          {(q.options ?? []).map((o) => (
            <Pressable key={o} accessibilityRole="radio" accessibilityState={{ checked: answers[q.key] === o }} onPress={() => setAnswers({ ...answers, [q.key]: o })}
              className={`h-11 justify-center rounded-xl border px-4 ${answers[q.key] === o ? 'border-2 border-primary-600 bg-primary-50' : 'border-line'}`}>
              <Text className="font-sans text-body text-ink">{o}</Text>
            </Pressable>
          ))}
        </View>
      );
    }
    return <Field key={q.key} label={q.label} value={String(answers[q.key] ?? '')} onChangeText={(v) => setAnswers({ ...answers, [q.key]: v })} error={errors[`answers.${q.key}`]?.[0]} />;
  };

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" keyboardShouldPersistTaps="handled">
        <BackHeader />
        {!intake ? (
          <Body>{online ? t('common.loading') : t('apply.offline')}</Body>
        ) : queued ? (
          <Card className="gap-2">
            <Title>{t('apply.queuedTitle')}</Title>
            <Body>{t('apply.queuedBody')}</Body>
            <Button title={t('common.done')} onPress={() => router.back()} />
          </Card>
        ) : (
          <>
            <View className="gap-1">
              <Body>{intake.provider}</Body>
              <Title>{intake.title}</Title>
              <Body>{[intake.qualification?.title, intake.centre?.name, `${intake.starts_on} – ${intake.ends_on}`].filter(Boolean).join(' · ')}</Body>
            </View>
            {intake.description ? <Card><Body muted={false}>{intake.description}</Body></Card> : null}
            {errors.status ? <Notice tone="danger">{errors.status[0]}</Notice> : null}
            {needsId ? (
              <Field label={t('apply.idNumber')} keyboardType="number-pad" maxLength={13} value={idNumber} onChangeText={setIdNumber} error={errors.id_number?.[0]} />
            ) : null}
            {(intake.questions ?? []).map(answer)}
            {intake.document_types?.length ? <Notice>{t('apply.docsNext')}</Notice> : null}
            <Button title={online ? t('apply.send') : t('apply.saveOffline')} loading={submit.isPending} onPress={() => submit.mutate()} />
          </>
        )}
      </ScrollView>
    </Screen>
  );
}
