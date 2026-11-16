/** Paperless exam paper workflow: teacher writes → examiner reviews → principal approves. */
export const PAPER_STATUS: Record<string, { label: string; kind: string; next: string }> = {
  draft: { label: 'Draft', kind: '', next: 'Teacher is still writing' },
  submitted: { label: 'With examiner', kind: 'info', next: 'Waiting for the examiner to review' },
  reviewed: { label: 'With principal', kind: 'warn', next: 'Waiting for the principal to approve' },
  approved: { label: 'Approved', kind: 'ok', next: 'Ready to print' },
  returned: { label: 'Returned', kind: 'bad', next: 'Sent back to the teacher for changes' },
};
export const PAPER_STEPS = ['draft', 'submitted', 'reviewed', 'approved'];
