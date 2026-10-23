# Deployment filesystem boundary (F2)

The controller must not execute site-writable code as root or mutate a
site-writable pathname as root. `-P`, `-h` and `-T` do not protect the complete
ancestor chain.

## Protected upload

The action requires root SSH. Before upload it checks that `/`, `/var`,
`/var/lib` and `/var/lib/kssmi-deploy` are real, root-owned directories without
group/other write permission. A **new** release-ID directory is created `0700`;
existing directories are rejected. SCP and its temporary archive use this
protected tree. The enclosing `0700` directory prevents access while extraction
may preserve runner UIDs. The trusted inline action rejects symlinks, changes
the upload to root ownership, then grants read/traverse without group/other
write. Activation, finalization, rollback, evidence and pruning use its scripts.
The controller sources its protected policy; existing permission checks still
validate the separate site-owned release copy.

The site account copies the readable upload into a new release. No root chown
of a site-owned tree is needed. Directory creation, permission normalization,
links, probes, backup/restore, email migration, pointer changes, marker writes
and pruning run as site. Substituted ancestors can affect files that this same
account already controls, but cannot grant root filesystem privileges. Root's
wrapper accepts only the two existing fixed worker-control commands.

Root, CI artifacts and the protected controller remain trusted. This does not
protect site data or deployment availability against a compromised site account.
Incorrect ownership of existing site files fails closed and requires separately
authorized provisioning; deployment no longer repairs it as root.

## Atomic writes and locks

State/marker writes and installed files use unpredictable same-directory temp
files followed by `mv -Tf`, entirely as site. Missing locks are published with
`ln -T`; existing lock inodes are retained, and links/non-regular files rejected.
The deploy lock is prepared before acquisition for every command. All lock FDs
are read-only. Ordinary local Linux `flock` supports this; NFS exclusive-lock
semantics are outside this contract. Verify the target filesystem before rollout.

## Lifecycle and recovery

The protected upload and site release coexist temporarily. The pre-upload free
space floor increases from 6 to 12 GiB for the extra copy. Successful uploads
are removed after finalize, prune and evidence gates. Failed uploads are retained
for diagnosis/manual recovery and consume space; cleanup needs separate
authorization. Retry using a new workflow attempt/release ID.

Never execute a release copy of the deployment scripts as root. Failed-run
recovery uses the retained protected controller with the same environment and
release ID after reviewing failure/rollback state. Do not trigger rollback after
successful finalization/pruning.

## Verification and F1

`test:deploy-fs-safety` checks link rejection, atomic replacement, stable lock
inodes, read-only lock contention and ordinary materialization on Linux. A
separate `sudo env F2_ROOT_TEST=1 bash scripts/test-deploy-fs-safety.sh` tests the
real root-to-nobody switch against root-only sentinels in a temporary directory,
and a legitimate site write. Tests invoke no production/network/worker actions.
Both workflows run these and `validate:fs-safety` alongside the existing
credential-transition tests.

Windows syntax/static checks cannot establish Linux UID/DAC/flock behavior.
Linux behavioral gates must pass before accepting the candidate. This patch
does not authorize production rollout.

F1 is included in this candidate: rollback checks every required step even
when outer errexit is disabled. Failed barrier proof or restore stops rollback
without clearing the barrier or claiming completion. Corrected retries remain
possible. Finalization closes the automatic rollback window before pruning.
The isolated rollback-failure suite injects failures at each step and verifies
that no success marker or later step is reached, then verifies a successful retry.
