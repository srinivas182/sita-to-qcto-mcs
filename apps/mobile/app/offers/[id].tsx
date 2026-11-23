import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, api, errorMessage, json } from '@/lib/api';
import { useApp } from '@/lib/app';
import { useMyOffers, type Offer } from '@/lib/enrolment';

/** Accept a place (with POPIA consent, and a parent or guardian under 18) or decline it. */
export default function OfferScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, online } = useApp();
  const router = useRouter();
  const qc = useQueryClient();
  const o = (useMyOffers(online).data ?? []).find((x) => x.id === id);
  const [agree, setAgree] = useState(false);
  const [guardian, setGuardian] = useState({ name: '', phone: '', relationship: '' });
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const done = () => void qc.invalidateQueries({ queryKey: ['my-offers'] });
  const fail = (e: unknown) => setErrors(e instanceof ApiError ? e.fields : { offer: [errorMessage(e)] });
  const accept = useMutation({
    mutationFn: (offer: Offer) => json(api.POST(`/me/offers/${offer.id}/accept` as never, {
      body: { consent: agree, consent_version: offer.consent.version, guardian: offer.needs_guardian ? guardian : null }, headers: { 'X-Client': 'mobile' },
    } as never)),
    onSuccess: () => { setErrors({}); done(); void qc.invalidateQueries({ queryKey: ['my-agreements'] }); },
    onError: fail,
  });
  const decline = useMutation({
    mutationFn: () => json(api.POST(`/me/offers/${id}/decline` as never, { body: { reason: reason || null } } as never)),
    onSuccess: () => { done(); router.back(); },
    onError: fail,
  });
  const err = (k: string) => errors[k]?.[0] ?? null;

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" keyboardShouldPersistTaps="handled">
        <BackHeader />
        {!o ? <Body>{online ? t('common.loading') : t('home.offline')}</Body> : (
          <>
            <View className="gap-1">
              <Body>{o.provider} · {o.reference}</Body>
              <Title>{o.course.title}</Title>
              <Body>{`${o.course.cohort}: ${o.course.starts_on} – ${o.course.ends_on}`}</Body>
            </View>
            {o.status === 'accepted' ? (
              <Card className="gap-3">
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('offers.acceptedTitle')}</Text>
                <Body>{o.guardian ? t('offers.acceptedGuardian', { name: o.guardian.name }) : t('offers.acceptedBody')}</Body>
                {o.sign_signer_id ? <Button title={t('offers.sign')} onPress={() => router.push(`/agreements/${o.sign_signer_id}` as never)} /> : null}
              </Card>
            ) : o.status !== 'open' ? (
              <Notice>{t(`offers.${o.status}` as never)}</Notice>
            ) : declining ? (
              <Card className="gap-3">
                <Field label={t('offers.reason')} value={reason} onChangeText={setReason} />
                <Button title={t('offers.decline')} variant="danger" loading={decline.isPending} onPress={() => decline.mutate()} />
                <Button title={t('common.back')} variant="ghost" onPress={() => setDeclining(false)} />
              </Card>
            ) : (
              <>
                <Notice>{t('offers.replyBy', { date: o.expires_at.slice(0, 10) })}</Notice>
                <Card className="gap-2">
                  <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('offers.consentTitle', { provider: o.provider })}</Text>
                  {o.consent.text.split(/\n\s*\n/).map((p, i) => <Body key={i}>{p}</Body>)}
                </Card>
                {o.needs_guardian ? (
                  <Card className="gap-3">
                    <Text className="font-semibold text-body text-ink dark:text-night-ink">{t('offers.guardian')}</Text>
                    <Body>{t('offers.guardianWhy')}</Body>
                    <Field label={t('offers.guardianName')} value={guardian.name} onChangeText={(v) => setGuardian({ ...guardian, name: v })} error={err('guardian.name') ?? err('guardian')} />
                    <Field label={t('offers.guardianPhone')} keyboardType="phone-pad" value={guardian.phone} onChangeText={(v) => setGuardian({ ...guardian, phone: v })} error={err('guardian.phone')} />
                    <Field label={t('offers.guardianRelationship')} value={guardian.relationship} onChangeText={(v) => setGuardian({ ...guardian, relationship: v })} error={err('guardian.relationship')} />
                  </Card>
                ) : null}
                <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: agree }} onPress={() => setAgree(!agree)} className="flex-row items-center gap-3">
                  <View className={`h-6 w-6 items-center justify-center rounded-md border-2 ${agree ? 'border-primary-700 bg-primary-700' : 'border-line'}`}>
                    {agree ? <Text className="font-bold text-white">✓</Text> : null}
                  </View>
                  <Text className="flex-1 font-sans text-body text-ink dark:text-night-ink">{t('offers.agree')}</Text>
                </Pressable>
                {err('consent') ?? err('consent_version') ?? err('offer') ? <Notice tone="danger">{err('consent') ?? err('consent_version') ?? err('offer')}</Notice> : null}
                <Button title={t('offers.accept')} loading={accept.isPending} disabled={!online} onPress={() => accept.mutate(o)} />
                <Button title={t('offers.decline')} variant="ghost" onPress={() => setDeclining(true)} />
              </>
            )}
          </>
        )}
      </ScrollView>
    </Screen>
  );
}
