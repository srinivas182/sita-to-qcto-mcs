import { Badge } from '@/components/ui';
import { enrolmentBadge, type QualificationSummary } from './types';

/** Open / closing in N days / closed to new learners / ended. */
export function EnrolmentBadge({ q }: { q: Pick<QualificationSummary, 'enrolment' | 'days_to_last_enrolment'> }) {
  const b = enrolmentBadge[q.enrolment];
  return (
    <Badge tone={b.tone} dot>
      {b.label(q.days_to_last_enrolment)}
    </Badge>
  );
}
