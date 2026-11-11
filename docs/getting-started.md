# S3, IAM and your first backup

[日本語](getting-started-ja.md) · [Product overview](../README.md)

The goal is to save your WordPress database to S3 once and check the result. Images and other uploaded media are backed up separately afterwards. Resilience is not required.

Follow **hosting check → S3 bucket → IAM permissions → WordPress settings → connection test → first backup**.

## 1. Check your hosting

You need WordPress 5.9+, PHP 8.1+ and an AWS account. AWS storage and requests incur charges. This example uses EC2 and S3 in the same AWS account.

| Term | Meaning here |
|---|---|
| EC2 | The AWS server running WordPress |
| S3 bucket | Storage for backups, separate from your server |
| Region | Storage location; Tokyo is `ap-northeast-1` |
| Prefix | A name separating this site's files inside the bucket, such as `sites/my-site/` |
| IAM role | The permissions identity used by the server |
| IAM policy | Rules specifying which storage operations are allowed |

**On EC2:** someone who can identify the server and configure S3/IAM in the AWS console performs the steps below. Being a WordPress administrator does not itself grant AWS administration access.

**On other hosting:** first ask the provider whether PHP can use S3 through the AWS SDK default credential provider chain, and which server-side authentication method it supports. This plugin has no access-key entry fields. Without server-side credentials, this EC2 example alone cannot get you started.

If someone manages your server, give them this guide and ask them to provide the bucket, region, prefix and AWS authentication available to PHP. Do not paste credentials into support requests or public documents.

## 2. Create dedicated S3 storage

1. In the AWS console, open **S3 → Create bucket** and choose a general purpose bucket.
2. Choose a region, normally the same region as your WordPress EC2 instance.
3. Enter an available bucket name. Replace the example `replace-with-your-bucket` with your own name.
4. Keep **ACLs disabled (Bucket owner enforced)** and **Block all public access**. Backups do not need public access.
5. Use **SSE-S3 (Amazon S3 managed keys)** for default encryption. This example does not cover custom KMS keys or cross-account access.
6. Versioning is optional. With versioning, ordinary deletion leaves older versions, so a retention count alone does not reduce stored bytes. You do not need automatic deletion, archive transitions or Object Lock retention for this first setup. Have an administrator review existing deletion, encryption and lock rules if reusing a bucket.
7. Create the bucket and record these three values. Choose a dedicated prefix yourself; there is no need to create a folder in the S3 console.

| WordPress field | Example |
|---|---|
| AWS Region | `ap-northeast-1` |
| S3 Bucket | Your bucket name only, without `s3://` or a URL |
| S3 Prefix | `sites/my-site/` (different for each site) |

[AWS: creating a bucket](https://docs.aws.amazon.com/AmazonS3/latest/userguide/create-bucket-overview.html)

## 3. Grant the WordPress server access

In **EC2 → Instances → your WordPress server → Security**, inspect its IAM role.

- **Existing role:** it may serve other workloads. Keep the role, review its sharing and existing permissions, and add a dedicated product policy. Do not overwrite a shared policy.
- **No role:** use **IAM → Roles → Create role → AWS service → EC2**. After adding the policy below, attach the role through **EC2 → Actions → Security → Modify IAM role**.

Create the policy:

1. Open the [complete first-backup JSON](aws-first-backup-policy.json), choose GitHub's **Raw** view and copy the entire document, from the first `{` to the last `}`. Do not include line numbers or page instructions.
2. Replace **every** `replace-with-your-bucket` with your bucket name and **every** `sites/example-site/` with your chosen prefix. Keep the trailing `/` and the JSON's `*`.
3. Open **IAM → Roles → target role → Add permissions → Create inline policy → JSON** and paste the complete document.
4. Check the bucket and prefix and save with a descriptive name such as `OzekiBackupInitial`. If the dedicated policy already exists, edit it instead.

This policy permits saving, reading, multipart media transfers and deletion of connection-test files only. It does not permit retention deletion of database backups. The connection check needs bucket listing permission for the dedicated bucket; object reading/writing is restricted to the prefix. This example does not revoke permissions granted by other policies.

Other server processes may also use its EC2 role: this is not authentication isolation between plugins. WordPress does not need AWS administrator permissions.

[AWS: attaching an EC2 role](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/attach-iam-role.html)

## 4. Enter the destination in WordPress

1. Install and activate the plugin. Open **Settings → Ozeki Database Backup for S3**.
2. Enter the recorded **AWS Region / S3 Bucket / S3 Prefix**. No access keys are entered here.
   Faint placeholder text is an example, not a saved value. Enter your actual destination values.
3. Set **Schedule to Disabled** and **Retention to 0 (deletion disabled)**, then click **Save Changes**.
4. Click **Test Connection**. This creates a small test file in S3, reads it back and deletes it.
5. Check the success message. Resolve any failure before continuing.

| Message or symptom | Next check |
|---|---|
| Credentials not found | EC2 role attachment and authentication from web PHP. With Docker, host authentication alone is insufficient; ask the administrator to check the container |
| AccessDenied / 403 | Bucket/prefix substitutions, the actual role and existing deny rules. Identify the failing operation instead of granting broad access |
| Bucket or region error | Exact bucket name and region. A 403 alone does not prove that the bucket is absent |
| Reading works but the test fails | Permission to write/delete the test file, and existing restrictions such as Object Lock |
| Connection timeout | Server HTTPS access to S3, DNS and network restrictions |

## 5. Check the first database backup

1. Click **Backup Now** on the same page and wait for the completion result.
2. Under **Recent Backups**, check the new entry's time, success result, S3 location and size, and the stored-content result in **Verification**.
3. In the AWS console, confirm the new `.sql.gz` under your bucket's `prefix/backups/database/`, using the prefix you entered.
4. If it fails, inspect the message before repeating. Ask the server administrator about database export, temporary directory or disk-space errors.

You have now completed the **first database save and storage verification**. Images are not yet backed up. Practise restoration in an isolated environment without overwriting production.

## 6. Configure later, after the first success

- **Daily backups:** verify that WP-Cron runs before enabling the schedule. Low-traffic sites may need a server-side scheduler.
- **Retention deletion:** do not enable it with the first-backup policy unchanged. Choose a keep count, then add `s3:DeleteObject` for the dedicated prefix's `backups/database/*` to the product policy. Review versioned objects and Object Lock too. Manual Backup Now does not run retention deletion.
- **Images and other media:** have the administrator create a persistent directory outside web-accessible paths, owned by PHP's user with mode 0700, and define `ODBFS3_MEDIA_WORK_DIR` in `wp-config.php`. Then run **Start Media Backup**. This is separate from database backup. See [details](../readme.txt).

Keep the destination values and operating instructions somewhere accessible even if the original server is lost.
