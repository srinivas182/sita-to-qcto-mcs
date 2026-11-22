import { useState, type ReactNode } from 'react';
import { ActivityIndicator, Pressable, Text, TextInput, View, type PressableProps, type TextInputProps } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { useApp } from '@/lib/app';

/** Screen frame: safe areas, canvas background, standard 20 px gutters. */
export function Screen({ children, scroll = false }: { children: ReactNode; scroll?: boolean }) {
  return (
    <SafeAreaView className="flex-1 bg-canvas dark:bg-night-canvas" edges={['top', 'left', 'right']}>
      <OfflineBanner />
      <View className={`flex-1 px-5 ${scroll ? '' : 'pb-4'}`}>{children}</View>
    </SafeAreaView>
  );
}

export function Title({ children }: { children: ReactNode }) {
  return <Text className="font-semibold text-display text-ink dark:text-night-ink" accessibilityRole="header">{children}</Text>;
}

export function Body({ children, muted = true }: { children: ReactNode; muted?: boolean }) {
  return <Text className={`font-sans text-body ${muted ? 'text-muted dark:text-night-muted' : 'text-ink dark:text-night-ink'}`}>{children}</Text>;
}

export function Card({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <View className={`rounded-2xl border border-line bg-surface p-4 dark:border-night-line dark:bg-night-surface ${className}`}>{children}</View>;
}

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

export function Button({ title, variant = 'primary', loading, icon, ...rest }: PressableProps & { title: string; variant?: Variant; loading?: boolean; icon?: ReactNode }) {
  const styles: Record<Variant, { box: string; text: string }> = {
    primary: { box: 'bg-primary-700 active:bg-primary-800', text: 'text-white' },
    secondary: { box: 'border border-line bg-surface active:bg-line-soft dark:border-night-line dark:bg-night-surface', text: 'text-ink dark:text-night-ink' },
    ghost: { box: 'active:bg-line-soft', text: 'text-primary-700 dark:text-primary-300' },
    danger: { box: 'bg-danger-bg active:opacity-80 dark:bg-danger/20', text: 'text-danger dark:text-[#f2a9a5]' },
  };
  const disabled = rest.disabled || loading;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: !!disabled, busy: !!loading }}
      className={`h-12 flex-row items-center justify-center gap-2 rounded-xl px-5 ${styles[variant].box} ${disabled ? 'opacity-50' : ''}`}
      {...rest}
      disabled={disabled}
    >
      {loading ? <ActivityIndicator color={variant === 'primary' ? '#fff' : '#4338ca'} /> : icon}
      <Text className={`font-semibold text-body ${styles[variant].text}`}>{title}</Text>
    </Pressable>
  );
}

export function Field({ label, error, big, onFocus, onBlur, ...rest }: TextInputProps & { label: string; error?: string | null; big?: boolean }) {
  const [focused, setFocused] = useState(false);
  return (
    <View className="gap-1.5">
      <Text className="font-medium text-small text-ink dark:text-night-ink">{label}</Text>
      <TextInput
        accessibilityLabel={label}
        onFocus={(e) => {
          setFocused(true);
          onFocus?.(e);
        }}
        onBlur={(e) => {
          setFocused(false);
          onBlur?.(e);
        }}
        style={{ outlineStyle: 'none' } as object}
        placeholderTextColor="#8a90a3"
        className={`rounded-xl border bg-surface px-4 text-ink dark:bg-night-surface dark:text-night-ink ${big ? 'h-14 text-center font-semibold text-[22px] tracking-[8px]' : 'h-12 font-sans text-[15px]'} ${error ? 'border-danger' : focused ? 'border-2 border-primary-600' : 'border-line dark:border-night-line'}`}
        {...rest}
      />
      {error ? <Text className="font-sans text-small text-danger" accessibilityLiveRegion="polite">{error}</Text> : null}
    </View>
  );
}

export function Notice({ tone = 'info', children }: { tone?: 'info' | 'danger' | 'success' | 'warning'; children: ReactNode }) {
  const map = { info: 'bg-info-bg text-info', danger: 'bg-danger-bg text-danger', success: 'bg-success-bg text-success', warning: 'bg-warning-bg text-warning' }[tone];
  const [bg, fg] = map.split(' ');
  return (
    <View className={`rounded-xl px-4 py-3 ${bg}`} accessibilityLiveRegion="polite">
      <Text className={`font-sans text-small ${fg}`}>{children}</Text>
    </View>
  );
}

/** Thin banner at the top of every screen while offline. */
export function OfflineBanner() {
  const { online, t } = useApp();
  if (online) return null;
  return (
    <View className="mx-5 mt-1 flex-row items-center gap-2 rounded-lg bg-warning-bg px-3 py-2" accessibilityRole="alert">
      <View className="h-2 w-2 rounded-full bg-warning" />
      <Text className="flex-1 font-medium text-caption text-warning">{t('home.offline')}</Text>
    </View>
  );
}

export function Brand({ name, primary }: { name: string; primary?: string | undefined }) {
  return (
    <View className="flex-row items-center gap-3">
      <View className="h-11 w-11 items-center justify-center rounded-2xl" style={{ backgroundColor: primary ?? '#4338ca' }}>
        <Text className="font-bold text-[18px] text-white">{name.charAt(0)}</Text>
      </View>
      <Text className="font-semibold text-title text-ink dark:text-night-ink" numberOfLines={1}>{name}</Text>
    </View>
  );
}
