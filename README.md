# Invoice Ninja Cloud Run Deployment

This repository contains Docker configuration and GitHub Actions workflow to deploy Invoice Ninja to Google Cloud Run.

## Architecture

- **Dockerfile.fast**: Optimized Dockerfile that extends the official Invoice Ninja image with Nginx and Supervisor
- **nginx.conf**: Nginx configuration for reverse proxy
- **default.conf**: Nginx server block configuration
- **supervisord.conf**: Supervisor configuration to run PHP-FPM and Nginx together
- **php-fpm.conf**: PHP-FPM pool configuration

## GitHub Actions Setup

### Prerequisites

1. **Create Workload Identity Federation** (recommended over service account keys):

   ```bash
   # Enable required APIs
   gcloud services enable iamcredentials.googleapis.com

   # Create Workload Identity Pool
   gcloud iam workload-identity-pools create "github-pool" \
     --project="modern-alpha-479108-b6" \
     --location="global" \
     --display-name="GitHub Actions Pool"

   # Create Workload Identity Provider
   gcloud iam workload-identity-pools providers create-oidc "github-provider" \
     --project="modern-alpha-479108-b6" \
     --location="global" \
     --workload-identity-pool="github-pool" \
     --display-name="GitHub Provider" \
     --attribute-mapping="google.subject=assertion.sub,attribute.actor=assertion.actor,attribute.repository=assertion.repository" \
     --issuer-uri="https://token.actions.githubusercontent.com"

   # Create Service Account
   gcloud iam service-accounts create github-actions \
     --display-name="GitHub Actions Service Account"

   # Grant necessary permissions
   gcloud projects add-iam-policy-binding modern-alpha-479108-b6 \
     --member="serviceAccount:github-actions@modern-alpha-479108-b6.iam.gserviceaccount.com" \
     --role="roles/run.admin"

   gcloud projects add-iam-policy-binding modern-alpha-479108-b6 \
     --member="serviceAccount:github-actions@modern-alpha-479108-b6.iam.gserviceaccount.com" \
     --role="roles/artifactregistry.admin"

   gcloud projects add-iam-policy-binding modern-alpha-479108-b6 \
     --member="serviceAccount:github-actions@modern-alpha-479108-b6.iam.gserviceaccount.com" \
     --role="roles/iam.serviceAccountUser"

   gcloud projects add-iam-policy-binding modern-alpha-479108-b6 \
     --member="serviceAccount:github-actions@modern-alpha-479108-b6.iam.gserviceaccount.com" \
     --role="roles/secretmanager.secretAccessor"

   # Allow GitHub to impersonate service account
   gcloud iam service-accounts add-iam-policy-binding \
     github-actions@modern-alpha-479108-b6.iam.gserviceaccount.com \
     --project="modern-alpha-479108-b6" \
     --role="roles/iam.workloadIdentityUser" \
     --member="principalSet://iam.googleapis.com/projects/PROJECT_NUMBER/locations/global/workloadIdentityPools/github-pool/attribute.repository/YOUR_GITHUB_USERNAME/REPO_NAME"
   ```

2. **Add GitHub Secrets**:

   Go to your GitHub repository → Settings → Secrets and variables → Actions, and add:

   - `WIF_PROVIDER`: `projects/PROJECT_NUMBER/locations/global/workloadIdentityPools/github-pool/providers/github-provider`
   - `WIF_SERVICE_ACCOUNT`: `github-actions@modern-alpha-479108-b6.iam.gserviceaccount.com`

3. **Create Secret Manager Secrets** (if not already created):

   ```bash
   # APP_KEY
   echo -n "base64:YOUR_APP_KEY_HERE" | gcloud secrets create invoiceninja-app-key \
     --data-file=- \
     --replication-policy="automatic"

   # DB_PASSWORD
   echo -n "your-db-password" | gcloud secrets create invoiceninja-db-password \
     --data-file=- \
     --replication-policy="automatic"
   ```

## How It Works

1. Push to `main` branch triggers the GitHub Actions workflow
2. GitHub Actions authenticates to GCP using Workload Identity Federation
3. Docker image is built for `linux/amd64` platform
4. Image is pushed to Google Artifact Registry
5. Cloud Run service is deployed with the new image
6. Secrets are mounted from Secret Manager

## Manual Build and Deploy

If you want to build and deploy manually:

```bash
# Build the Docker image
docker buildx build --platform linux/amd64 -f Dockerfile.fast \
  -t europe-west1-docker.pkg.dev/modern-alpha-479108-b6/invoiceninja/invoiceninja:latest .

# Push to Artifact Registry
docker push europe-west1-docker.pkg.dev/modern-alpha-479108-b6/invoiceninja/invoiceninja:latest

# Deploy to Cloud Run
gcloud run deploy invoiceninja \
  --image=europe-west1-docker.pkg.dev/modern-alpha-479108-b6/invoiceninja/invoiceninja:latest \
  --platform=managed \
  --region=europe-west1 \
  --allow-unauthenticated \
  --port=8080
```

## Configuration Files

### Dockerfile.fast
Extends official Invoice Ninja image and adds:
- Nginx for HTTP server
- Supervisor to manage PHP-FPM and Nginx processes

### nginx.conf
Main Nginx configuration with:
- Worker processes auto-detection
- Client max body size: 100MB
- Logging to stdout/stderr for Cloud Run

### default.conf
Server block configuration:
- Listens on port 8080 (Cloud Run requirement)
- PHP-FPM proxy on port 9000
- Standard Laravel public directory setup

### supervisord.conf
Manages two processes:
- `php-fpm82`: PHP FastCGI Process Manager
- `nginx`: Web server

## Environment Variables

The following environment variables can be configured in Cloud Run:
- `APP_ENV`: Application environment (production)
- `APP_DEBUG`: Debug mode (false)
- `APP_KEY`: Laravel application key (from Secret Manager)
- `DB_HOST`: Database host
- `DB_DATABASE`: Database name
- `DB_USERNAME`: Database username
- `DB_PASSWORD`: Database password (from Secret Manager)

## Troubleshooting

### View logs
```bash
gcloud run services logs read invoiceninja --region=europe-west1
```

### Check service status
```bash
gcloud run services describe invoiceninja --region=europe-west1
```

### Test locally
```bash
docker run -p 8080:8080 \
  -e APP_KEY="base64:YOUR_KEY" \
  -e DB_HOST="your-db-host" \
  -e DB_DATABASE="ninja" \
  -e DB_USERNAME="ninja" \
  -e DB_PASSWORD="password" \
  europe-west1-docker.pkg.dev/modern-alpha-479108-b6/invoiceninja/invoiceninja:latest
```

## License

Invoice Ninja is licensed under the Elastic License 2.0.
