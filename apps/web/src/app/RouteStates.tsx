import { Link } from '@tanstack/react-router';
import { Button } from '@/components/ui';

export function NotFound() {
  return (
    <div className="grid min-h-[60vh] place-items-center p-6 text-center">
      <div>
        <div className="eyebrow">404</div>
        <h1 className="mt-2 text-page-title font-semibold">This page does not exist</h1>
        <Link to="/" className="mt-4 inline-block font-medium text-primary-700 hover:underline">
          Go to the overview
        </Link>
      </div>
    </div>
  );
}

export function RouteError({ error, reset }: { error: unknown; reset: () => void }) {
  return (
    <div className="grid min-h-[60vh] place-items-center p-6 text-center">
      <div className="max-w-md">
        <h1 className="text-page-title font-semibold">Something went wrong</h1>
        <p className="mt-2 text-muted">{error instanceof Error ? error.message : 'Please try again.'}</p>
        <Button className="mt-4" onClick={reset}>
          Try again
        </Button>
      </div>
    </div>
  );
}
