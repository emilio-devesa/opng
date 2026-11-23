# v1.3 development: interim containment (S01/S02, D01/D02)

For a development-only installation with no external exposure, record that
decision internally and keep local test flows available. Check the effective
listener separately: a wildcard bind is not a loopback-only bind, nor is it
proof of Internet reachability. Reassess before enabling LAN access, a tunnel
or a public deployment. The perimeter restrictions below are for exposed
installations and require deployment-owner approval.

1. **Inventory.** For each classic deployment, MAMP document root or running
   container, record environment, internal owner, deployment time, trusted
   release checksum or running image digest, and which origins are reachable.
   From a trusted repository checkout, compare a read-only copy of its `src`:
   `bash tests/distribution_inventory.sh v1.2.0 /path/to/installed/src`.
   Repeat with the candidate commit. Exit 0 identifies matching sampled files,
   not an authentic complete release or an effective access policy. Record
   unknowns explicitly; a footer or image tag is insufficient. Do not collect
   config.php, bearer tokens, dump contents or query strings.
2. **Proposed temporary access policy.** Until S01 is fixed, suspend external
   access to the API namespace, including its front controller, at every ingress
   and direct origin. Until S02 is fixed, suspend the thread-to-trash action
   (`admin/drop_id.php`) for all web users. A prefix called `admin` does not
   imply administrator-only authorization. These measures interrupt legitimate
   API clients and deletions. If hosting cannot enforce them reliably, ask the
   owner to approve restricted access or a maintenance window. Do not enable
   new API rewrites. Do not substitute hiding buttons for server-side controls.
3. **Preserve evidence and backups.** The data owner retains previous dumps and
   their existing sidecars in restricted, versioned or immutable storage outside
   automated pruning. Keep subsequent backups under fresh names and continue
   the backup schedule. Record capture time, size, digest and custody separately.
   Do not mark a copy recoverable solely on a checksum, HTTP success or the
   application's “Verified” message. Restore a working copy on an isolated
   instance and compare schema, counts and representative values with independent
   snapshot evidence; record “completeness unknown” if no baseline exists.
4. **Verify and communicate.** After approval, use synthetic staging data first:
   confirm the perimeter denies the affected features before PHP processes them,
   ordinary sign-in and viewing remain available, and no thread state changes.
   Repeat non-destructive checks on every approved origin. Record time, operator,
   policy and status only. Security reviews existing access/audit records with
   restricted access; absence of logs is not proof of absence of exposure.
   Release owner informs affected operators of restrictions, expected disruption
   and next update. If evidence indicates unauthorized disclosure or removal,
   escalate privately to the security/privacy owner for impact assessment and
   notification decisions. Do not publish secrets or customer identifiers.
5. **Exit.** Record named security, data and release owners, actual installation
   inventory, approved policy, verification results, preserved-copy location
   and communication decision in the private incident record. Remove temporary
   restrictions only after the relevant fixes pass regression tests in the
   deployed build and the owner approves reopening. D01/D02 remain open until
   the backup fixes are independently verified.
