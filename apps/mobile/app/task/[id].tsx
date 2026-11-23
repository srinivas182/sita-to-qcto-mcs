import { useQueryClient } from '@tanstack/react-query';
import { CameraView, useCameraPermissions } from 'expo-camera';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { Image, Pressable, ScrollView, Text, View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, api, errorMessage, json } from '@/lib/api';
import { useApp } from '@/lib/app';
import { useCourseTasks } from '@/lib/delivery';
import { uploadPhoto } from '@/lib/enrolment';
import { outbox } from '@/lib/outbox';

/** Answer a task. Without evidence it can be handed in offline (sent later with the time). */
export default function TaskScreen() {
  const { id, enrolment } = useLocalSearchParams<{ id: string; enrolment: string }>();
  const { t, online, syncNow } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const task = (useCourseTasks(enrolment, online).data ?? []).find((x) => x.id === id);
  const [answers, setAnswers] = useState<Record<string, string | number[]>>({});
  const [photos, setPhotos] = useState<string[]>([]);
  const [camera, setCamera] = useState(false);
  const [permission, request] = useCameraPermissions();
  const cam = useRef<CameraView>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'danger' | 'success' | 'info'; text: string } | null>(null);

  if (!task) {
    return (
      <Screen>
        <BackHeader />
        <Body>{online ? t('common.loading') : t('home.offline')}</Body>
      </Screen>
    );
  }
  const last = task.attempts[task.attempts.length - 1];
  const closed = task.attempts.some((a) => a.status === 'passed') || last?.status === 'submitted' || task.attempts.length >= task.attempts_allowed;

  const send = async () => {
    setBusy(true);
    setMessage(null);
    try {
      if (!online) {
        if (task.evidence) throw new Error(t('task.needsOnline'));
        await outbox.enqueue({ stream: 'tasks', method: 'POST', path: `/me/tasks/${task.id}/attempts`, body: { answers, submitted_at: new Date().toISOString() } });
        setMessage({ tone: 'info', text: t('task.saved') });
        return;
      }
      const fileIds: string[] = [];
      for (const [n, uri] of photos.entries()) fileIds.push(await uploadPhoto('task_evidence', uri, `evidence-${n + 1}.jpg`));
      const r = await json<{ data: { status: string; score_pct: number | null } }>(api.POST(`/me/tasks/${task.id}/attempts` as never, { body: { answers, file_ids: fileIds } } as never));
      void qc.invalidateQueries({ queryKey: ['course-tasks', enrolment] });
      void syncNow();
      setMessage({ tone: 'success', text: r.data.status === 'submitted' ? t('task.handedIn') : `${t(`task.${r.data.status}` as never)} ${r.data.score_pct ?? ''}%` });
    } catch (e) {
      setMessage({ tone: 'danger', text: e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : e instanceof Error ? e.message : errorMessage(e) });
    } finally {
      setBusy(false);
    }
  };

  if (camera) {
    if (!permission?.granted) {
      return (
        <Screen>
          <View className="flex-1 justify-center gap-4"><Body>{t('docs.cameraWhy')}</Body></View>
          <Button title={t('docs.allowCamera')} onPress={() => void request()} />
        </Screen>
      );
    }
    return (
      <View className="flex-1 bg-black">
        <CameraView ref={cam} style={{ flex: 1 }} />
        <View className="absolute inset-x-0 bottom-10 items-center gap-3">
          <Pressable accessibilityRole="button" accessibilityLabel={t('docs.take')} className="h-20 w-20 rounded-full border-4 border-white bg-white/20"
            onPress={() => void cam.current?.takePictureAsync({ quality: 0.7 }).then((p) => { if (p) setPhotos((x) => [...x, p.uri]); setCamera(false); })} />
          <Button title={t('common.cancel')} variant="secondary" onPress={() => setCamera(false)} />
        </View>
      </View>
    );
  }

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" keyboardShouldPersistTaps="handled">
        <BackHeader />
        <Title>{task.title}</Title>
        {task.instructions ? <Body>{task.instructions}</Body> : null}
        {last?.feedback ? <Notice tone="info">{t('task.feedback', { text: last.feedback })}</Notice> : null}
        {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
        {closed && !message ? <Notice>{last?.status === 'submitted' ? t('task.waiting') : last?.status === 'passed' ? t('task.passedAlready') : t('task.noAttempts')}</Notice> : null}
        {!closed && !message
          ? task.questions.map((q, i) => (
              <Card key={q.id} className="gap-2">
                <Text className="font-medium text-body text-ink dark:text-night-ink">{i + 1}. {q.text}</Text>
                {q.type === 'short' ? (
                  <Field label={t('task.answer')} multiline value={String(answers[q.id] ?? '')} onChangeText={(v) => setAnswers({ ...answers, [q.id]: v })} />
                ) : (
                  (q.options ?? []).map((o, n) => {
                    const picked = (answers[q.id] as number[] | undefined) ?? [];
                    const on = picked.includes(n);
                    return (
                      <Pressable key={n} accessibilityRole={q.type === 'single' ? 'radio' : 'checkbox'} accessibilityState={{ checked: on }}
                        onPress={() => setAnswers({ ...answers, [q.id]: q.type === 'single' ? [n] : on ? picked.filter((x) => x !== n) : [...picked, n] })}
                        className={`min-h-11 justify-center rounded-xl border px-4 py-2 ${on ? 'border-2 border-primary-600 bg-primary-50' : 'border-line'}`}>
                        <Text className="font-sans text-body text-ink">{o}</Text>
                      </Pressable>
                    );
                  })
                )}
              </Card>
            ))
          : null}
        {!closed && !message && task.evidence ? (
          <Card className="gap-2">
            <Text className="font-medium text-body text-ink dark:text-night-ink">{t('task.evidence')}</Text>
            {task.evidence_hint ? <Body>{task.evidence_hint}</Body> : null}
            <View className="flex-row flex-wrap gap-2">
              {photos.map((p) => <Image key={p} source={{ uri: p }} accessibilityLabel={t('task.photo')} style={{ width: 80, height: 80, borderRadius: 12 }} />)}
            </View>
            <Button title={t('task.addPhoto')} variant="secondary" onPress={() => setCamera(true)} />
          </Card>
        ) : null}
        {!closed && !message ? <Button title={online ? t('task.handIn') : t('task.handInLater')} loading={busy} onPress={() => void send()} /> : null}
        {message ? <Button title={t('common.done')} onPress={() => router.back()} /> : null}
      </ScrollView>
    </Screen>
  );
}
