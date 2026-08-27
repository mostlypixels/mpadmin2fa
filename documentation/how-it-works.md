# How Admin 2FA works on PrestaShop 9

## Terms

Two-factor authentication (2FA) uses two proofs of identity.
The employee password is the first proof.
A time-based one-time password (TOTP) is the second proof.
An authenticator application makes the TOTP code.

A recovery code is a single-use code.
It gives access when the employee cannot use the authenticator application.

A step-up check asks for a new TOTP code before a sensitive change.

## Sign-in flow

1. Sign in to the back office through HTTPS.
2. The login subscriber starts a new module session.
3. The HTTPS guard checks the connection.
4. The guard rejects an insecure login when the policy requires 2FA.
5. The request subscriber reads the employee and the policy.
6. The subscriber sends the employee to enrollment or to a challenge.

The default policy requires 2FA for SuperAdmin employees.
An administrator can require 2FA for all employees or for selected profiles.

## Enrollment flow

1. Open **Admin 2FA > Your authenticator**.
2. Start the authenticator setup.
3. Wait for approval if the employee profile requires approval.
4. Scan the QR code with an authenticator application.
5. Enter the current six-digit code.
6. Save the recovery codes in a secure place.
7. Confirm that you saved the codes.

The module shows each recovery code one time.
The database stores only a password hash of each recovery code.

A SuperAdmin cannot approve their own request.
A different authorized employee must approve it.
The first SuperAdmin factor can start without an approval from an active SuperAdmin.

## Normal challenge flow

1. Enter the employee email and password.
2. Enter the current authenticator code.
3. Continue to the original back-office page.

The module rejects a code that it accepted before.
The module limits failed enrollment, challenge, recovery, and step-up attempts.
It writes failures and successes to the activity log.
It sends alerts after repeated failures.

## Recovery flow

1. Select the recovery-code option on the challenge page.
2. Enter one unused recovery code.
3. Set up a new authenticator immediately.
4. Save the new recovery codes.

The recovery session has restricted access.
The employee cannot use other back-office pages before the new enrollment is complete.
The module marks the recovery code as used.

## Step-up flow

The module requires a recent TOTP code before these changes:

- Install, configure, update, or remove a module.
- Import or enable a theme.
- Approve an enrollment request.
- Reset another employee factor.
- Change the module security policy.

The default step-up period is 300 seconds.
An administrator can change this period.
The module returns the employee to a safe page after the check.

## Manage your factor

Open **Admin 2FA > Your authenticator**.
You can replace the authenticator, make new recovery codes, or turn off 2FA.

The module can ask for the employee password and a TOTP code.
It uses the password age policy to make this decision.
An employee cannot turn off 2FA when the shop policy requires it.

## Administrator tasks

Use **Employee 2FA** to see enrollment states.
Use **Pending approvals** to approve an eligible request.
Use **Security** to set the policy and alert recipients.
Use **Activity log** to review security events.

The command line also supplies these maintenance commands:

- `mpadmin2fa:key:health` checks the protected data key.
- `mpadmin2fa:key:rotate prepare` prepares a cookie-key change.
- `mpadmin2fa:key:rotate commit` completes the cookie-key change.
- `mpadmin2fa:audit:prune` deletes old audit events.
- `mpadmin2fa:factor:reset <email>` resets one employee factor.

Read the command output before you change an encryption key.
Do not skip a phase of the key rotation.
