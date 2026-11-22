import { useSearch } from '@tanstack/react-router';
import { ResumePage } from '@/features/apply/ResumePage';

export function ApplyResumeRoute() {
  const { ref } = useSearch({ from: '/apply/resume' });
  return <ResumePage reference={ref} />;
}
