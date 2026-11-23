import Feather from '@expo/vector-icons/Feather';
import { useQueryClient } from '@tanstack/react-query';
import * as Sharing from 'expo-sharing';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Linking, Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Button, Card, Notice, Screen, Title } from '@/components/ui';
import { megabytes } from '@/core/offlinePack';
import { api, errorMessage, json } from '@/lib/api';
import { useApp } from '@/lib/app';
import { heldMaterials, packFor, syncPack, useCourseMaterials, useCourseTasks, type CourseMaterial } from '@/lib/delivery';

const ICON = { document: 'file-text', video: 'film', image: 'image', link: 'link' } as const;

/** One course: materials by module (kept on the phone for offline use) and tasks. */
export default function Course() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, online } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const materials = useCourseMaterials(id, online);
  const tasks = useCourseTasks(id, online);
  const [held, setHeld] = useState(heldMaterials());
  const [syncing, setSyncing] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const keep = async () => {
    setError(null);
    try {
      const pack = await packFor(id);
      setSyncing(t('course.downloading', { size: megabytes(pack.total_bytes) }));
      await syncPack(id, (done, of) => setSyncing(t('course.progress', { done, of })));
      setHeld(heldMaterials());
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setSyncing(null);
    }
  };

  const open = async (m: CourseMaterial) => {
    setError(null);
    try {
      const local = held[m.id];
      if (local && local.version === m.version) {
        if (m.kind === 'video') router.push({ pathname: '/video', params: { uri: local.path, title: m.title } } as never);
        else await Sharing.shareAsync(local.path, { mimeType: m.mime ?? undefined, dialogTitle: m.title });
      } else if (m.kind === 'link' && m.url) {
        await Linking.openURL(m.url);
      } else {
        const r = await json<{ data: { url: string; via: string } }>(api.GET(`/me/materials/${m.id}/download${m.kind === 'video' && m.low_size ? '?low=1' : ''}` as never));
        if (m.kind === 'video' && r.data.via === 'direct') router.push({ pathname: '/video', params: { uri: r.data.url, title: m.title } } as never);
        else await Linking.openURL(r.data.url);
      }
      void json(api.POST(`/me/materials/${m.id}/progress` as never, { body: { completed: m.completed } } as never)).catch(() => undefined);
    } catch (e) {
      setError(errorMessage(e));
    }
  };

  const markDone = async (m: CourseMaterial) => {
    await json(api.POST(`/me/materials/${m.id}/progress` as never, { body: { completed: !m.completed } } as never)).catch(() => undefined);
    void qc.invalidateQueries({ queryKey: ['course-materials', id] });
  };

  const data = materials.data;
  const groups = data ? [...data.modules.map((m) => ({ key: m.id, title: `${m.code} ${m.title}` })), { key: '', title: t('course.whole') }] : [];
  const keptCount = (data?.materials ?? []).filter((m) => held[m.id]?.version === m.version).length;
  const offlineable = (data?.materials ?? []).filter((m) => m.kind !== 'link').length;

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" refreshControl={<RefreshControl refreshing={materials.isRefetching} onRefresh={() => void Promise.all([materials.refetch(), tasks.refetch()])} />}>
        <BackHeader />
        <Title>{t('course.title')}</Title>
        {error ? <Notice tone="danger">{error}</Notice> : null}
        {offlineable ? (
          <Card className="gap-2">
            <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('course.offline')}</Text>
            <Body>{t('course.kept', { n: keptCount, of: offlineable })}</Body>
            <Button title={syncing ?? t('course.keep')} loading={syncing !== null} disabled={!online} variant="secondary" onPress={() => void keep()} />
          </Card>
        ) : null}
        {groups.map((g) => {
          const list = (data?.materials ?? []).filter((m) => (m.module_id ?? '') === g.key);
          if (!list.length) return null;
          return (
            <View key={g.key || 'all'} className="gap-2">
              <Text className="font-semibold text-small text-muted">{g.title}</Text>
              {list.map((m) => (
                <Card key={m.id} className="flex-row items-center gap-3">
                  <Feather name={ICON[m.kind]} size={20} color="#4338ca" />
                  <Pressable accessibilityRole="button" className="flex-1" onPress={() => void open(m)}>
                    <Text className="font-medium text-body text-ink dark:text-night-ink">{m.title}</Text>
                    <Text className="font-sans text-small text-muted">
                      {held[m.id]?.version === m.version ? t('course.onPhone') : m.kind === 'video' && m.low_size ? `${megabytes(m.low_size)} · ${t('course.lowData')}` : m.size ? megabytes(m.size) : ''}
                    </Text>
                  </Pressable>
                  <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: m.completed }} accessibilityLabel={t('course.done')} onPress={() => void markDone(m)}
                    className={`h-8 w-8 items-center justify-center rounded-full border-2 ${m.completed ? 'border-success bg-success' : 'border-line'}`}>
                    {m.completed ? <Feather name="check" size={16} color="#fff" /> : null}
                  </Pressable>
                </Card>
              ))}
            </View>
          );
        })}
        {data && !data.materials.length ? <Body>{t('course.none')}</Body> : null}

        {(tasks.data ?? []).length ? <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('course.tasks')}</Text> : null}
        {(tasks.data ?? []).map((task) => {
          const last = task.attempts[task.attempts.length - 1];
          return (
            <Pressable key={task.id} accessibilityRole="button" onPress={() => router.push({ pathname: '/task/[id]', params: { id: task.id, enrolment: id } } as never)}>
              <Card className="gap-1">
                <Text className="font-medium text-body text-ink dark:text-night-ink">{task.title}</Text>
                {last ? (
                  <Pill text={`${t(`task.${last.status}` as never)}${last.score_pct !== null ? ` ${last.score_pct}%` : ''}`} tone={last.status === 'passed' ? 'success' : last.status === 'submitted' ? 'warning' : 'danger'} />
                ) : (
                  <Pill text={t('task.todo')} tone="info" />
                )}
              </Card>
            </Pressable>
          );
        })}
      </ScrollView>
    </Screen>
  );
}
