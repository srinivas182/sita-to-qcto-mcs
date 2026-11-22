import Feather from '@expo/vector-icons/Feather';
import { Text, View } from 'react-native';
import { useApp } from '@/lib/app';
import type { Me } from '@/lib/me';

/**
 * The provider's account state, in plain words. Read-only: learners can still see
 * everything and keep working offline; changes are kept on the phone and sent once
 * the provider is active again. Suspended: nothing new can be sent until then.
 */
export function AccountStatus({ me }: { me: Me | undefined }) {
  const { t } = useApp();
  const status = me?.tenant?.billing?.status;
  if (status !== 'read_only' && status !== 'suspended') return null;
  const suspended = status === 'suspended';

  return (
    <View
      accessibilityRole="alert"
      className={`flex-row gap-3 rounded-2xl p-4 ${suspended ? 'bg-danger-bg' : 'bg-warning-bg'}`}
    >
      <Feather name={suspended ? 'pause-circle' : 'lock'} size={20} color={suspended ? '#a3262f' : '#8a5a0b'} />
      <View className="flex-1 gap-1">
        <Text className={`font-semibold text-body ${suspended ? 'text-danger' : 'text-warning'}`}>
          {t(suspended ? 'status.suspendedTitle' : 'status.readOnlyTitle', { name: me?.tenant?.name ?? '' })}
        </Text>
        <Text className="font-sans text-small text-ink dark:text-night-ink">{t(suspended ? 'status.suspendedBody' : 'status.readOnlyBody')}</Text>
      </View>
    </View>
  );
}
