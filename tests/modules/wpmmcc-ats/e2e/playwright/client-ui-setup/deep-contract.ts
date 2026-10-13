/** Deep acceptance never treats an absent job as successful UI setup. */
export function requireDeepJobs(jobs: unknown): number {
  if (!Array.isArray(jobs) || jobs.length === 0) {
    throw new Error('deep acceptance requires at least one persisted job');
  }
  return jobs.length;
}
