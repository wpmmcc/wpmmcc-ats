/**
 * TST-05: nonempty job evidence required, regardless of optional Lab flags.
 * covers: success|failure|boundary
 */
import { test, expect } from '@playwright/test';
import { requireDeepJobs } from '../client-ui-setup/deep-contract';

test('TST-05: zero/missing/malformed jobs are failures, never optional success', () => {
  for (const jobs of [[], undefined, null, {}, { length: 1 }, 'one job']) {
    expect(() => requireDeepJobs(jobs)).toThrow(/at least one persisted job/);
  }
});

test('TST-05: one or more real job rows provide nonempty evidence', () => {
  expect(requireDeepJobs([{ id: 'owned-job-1' }])).toBe(1);
  expect(requireDeepJobs([{ id: 'owned-job-1' }, { id: 'owned-job-2' }])).toBe(2);
});
