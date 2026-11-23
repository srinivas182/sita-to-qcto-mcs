import { useLocalSearchParams } from 'expo-router';
import { useVideoPlayer, VideoView } from 'expo-video';
import { View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Screen, Title } from '@/components/ui';

/** Plays a course video, from the phone when it is kept offline. */
export default function Video() {
  const { uri, title } = useLocalSearchParams<{ uri: string; title: string }>();
  const player = useVideoPlayer(uri, (p) => {
    p.play();
  });
  return (
    <Screen>
      <BackHeader />
      <View className="gap-3 pt-2">
        <Title>{title}</Title>
        <VideoView player={player} style={{ width: '100%', aspectRatio: 16 / 9, borderRadius: 16 }} nativeControls />
      </View>
    </Screen>
  );
}
