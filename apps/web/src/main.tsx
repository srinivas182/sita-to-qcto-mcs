import '@fontsource-variable/inter/wght.css';
import './styles.css';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { ToastProvider } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { I18nProvider } from '@/lib/i18n';
import { brandingQuery } from '@/lib/session';
import { makeRouter } from './app/router';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: true,
      retry: (count, error) => !(error instanceof ApiError && error.status < 500) && count < 2,
    },
  },
});

// Start the branding request before React renders, so the sign-in page paints branded.
void queryClient.prefetchQuery(brandingQuery);

const router = makeRouter(queryClient);

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <I18nProvider>
      <QueryClientProvider client={queryClient}>
        <ToastProvider>
          <RouterProvider router={router} />
        </ToastProvider>
      </QueryClientProvider>
    </I18nProvider>
  </StrictMode>,
);
