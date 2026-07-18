Run the general inbox triage flow (see the `mails` skill).

Run this command and report the results:
```
python3 .claude/skills/mails/mails.py scan $ARGUMENTS
```

Then, following the `mails` skill's rules:
- List what was auto-answered (already sent + filed — nothing to do).
- For every other category, show the draft (to/subject/body) for the admin to review — do not send
  anything without explicit confirmation, even if you're confident about the answer.
- Anything you're not sure how to answer still gets a draft, not silence.
