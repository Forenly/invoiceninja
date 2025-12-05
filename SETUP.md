# Invoice Ninja Cloud Run - Kurulum Kılavuzu

Bu döküman, Invoice Ninja'yı Google Cloud Run'da sıfırdan kurma adımlarını detaylı olarak açıklar.

## İçindekiler
1. [Ön Gereksinimler](#ön-gereksinimler)
2. [GCP Altyapısı Kurulumu](#gcp-altyapısı-kurulumu)
3. [GitHub Actions Kurulumu](#github-actions-kurulumu)
4. [Deployment](#deployment)
5. [Invoice Ninja İlk Kurulum](#invoice-ninja-ilk-kurulum)

## Ön Gereksinimler

- Google Cloud Platform hesabı
- GitHub hesabı
- `gcloud` CLI kurulu
- `git` kurulu

## GCP Altyapısı Kurulumu

### 1. GCP Projesini Oluştur

```bash
# Proje oluştur
gcloud projects create [PROJECT_ID]

# Projeyi aktif et
gcloud config set project [PROJECT_ID]

# Billing hesabını bağla (GCP Console'dan yapılmalı)
```

### 2. Gerekli API'leri Etkinleştir

```bash
gcloud services enable \
  run.googleapis.com \
  sql-component.googleapis.com \
  sqladmin.googleapis.com \
  secretmanager.googleapis.com \
  artifactregistry.googleapis.com \
  iamcredentials.googleapis.com
```

### 3. Secret Manager'da Secrets Oluştur

```bash
# Laravel APP_KEY oluştur
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;" > app-key.txt

# Secret Manager'a ekle
cat app-key.txt | gcloud secrets create invoiceninja-app-key \
  --data-file=- \
  --replication-policy="automatic"

# Database şifresi oluştur ve ekle
echo -n "güçlü-şifreniz-buraya" | gcloud secrets create invoiceninja-db-password \
  --data-file=- \
  --replication-policy="automatic"

# Temizlik
rm app-key.txt
```

### 4. Cloud SQL Database Oluştur

```bash
# Cloud SQL instance oluştur (20-25 dakika sürer)
gcloud sql instances create invoiceninja-db \
  --database-version=MYSQL_8_0 \
  --tier=db-f1-micro \
  --region=us-central1

# Database oluştur
gcloud sql databases create ninja --instance=invoiceninja-db

# Kullanıcı oluştur
gcloud sql users create ninja \
  --instance=invoiceninja-db \
  --password=$(gcloud secrets versions access latest --secret=invoiceninja-db-password)

# Public IP'yi not edin
gcloud sql instances describe invoiceninja-db --format="value(ipAddresses[0].ipAddress)"
```

### 5. Storage Bucket Oluştur (Opsiyonel)

```bash
gcloud storage buckets create gs://[PROJECT_ID]-invoiceninja-storage \
  --location=us-central1
```

## GitHub Actions Kurulumu

### 1. Workload Identity Federation Kur

```bash
# Project number'ı al
PROJECT_NUMBER=$(gcloud projects describe [PROJECT_ID] --format="value(projectNumber)")

# Workload Identity Pool oluştur
gcloud iam workload-identity-pools create "github-pool" \
  --project="[PROJECT_ID]" \
  --location="global" \
  --display-name="GitHub Actions Pool"

# OIDC Provider oluştur
gcloud iam workload-identity-pools providers create-oidc "github-provider" \
  --project="[PROJECT_ID]" \
  --location="global" \
  --workload-identity-pool="github-pool" \
  --display-name="GitHub Provider" \
  --attribute-mapping="google.subject=assertion.sub,attribute.actor=assertion.actor,attribute.repository=assertion.repository" \
  --attribute-condition="assertion.repository_owner=='[GITHUB_USERNAME]'" \
  --issuer-uri="https://token.actions.githubusercontent.com"

# Service Account oluştur
gcloud iam service-accounts create github-actions \
  --display-name="GitHub Actions Service Account"

# Gerekli rolleri ekle
gcloud projects add-iam-policy-binding [PROJECT_ID] \
  --member="serviceAccount:github-actions@[PROJECT_ID].iam.gserviceaccount.com" \
  --role="roles/run.admin"

gcloud projects add-iam-policy-binding [PROJECT_ID] \
  --member="serviceAccount:github-actions@[PROJECT_ID].iam.gserviceaccount.com" \
  --role="roles/artifactregistry.admin"

gcloud projects add-iam-policy-binding [PROJECT_ID] \
  --member="serviceAccount:github-actions@[PROJECT_ID].iam.gserviceaccount.com" \
  --role="roles/iam.serviceAccountUser"

gcloud projects add-iam-policy-binding [PROJECT_ID] \
  --member="serviceAccount:github-actions@[PROJECT_ID].iam.gserviceaccount.com" \
  --role="roles/secretmanager.secretAccessor"

# WIF binding
gcloud iam service-accounts add-iam-policy-binding \
  github-actions@[PROJECT_ID].iam.gserviceaccount.com \
  --project="[PROJECT_ID]" \
  --role="roles/iam.workloadIdentityUser" \
  --member="principalSet://iam.googleapis.com/projects/${PROJECT_NUMBER}/locations/global/workloadIdentityPools/github-pool/attribute.repository/[GITHUB_USERNAME]/invoiceninja"
```

### 2. GitHub Secrets Ekle

GitHub repository → Settings → Secrets and variables → Actions:

- **WIF_PROVIDER**: `projects/[PROJECT_NUMBER]/locations/global/workloadIdentityPools/github-pool/providers/github-provider`
- **WIF_SERVICE_ACCOUNT**: `github-actions@[PROJECT_ID].iam.gserviceaccount.com`

### 3. Workflow Dosyasını Güncelle

`.github/workflows/deploy.yml` dosyasında:
- `PROJECT_ID` değerini güncelleyin
- `REGION` değerini güncelleyin (varsayılan: europe-west1)
- `DB_HOST` değerini Cloud SQL IP'si ile güncelleyin
- `APP_URL` değerini Cloud Run URL'si ile güncelleyin

## Deployment

### İlk Deployment

```bash
# Repository'yi clone edin
git clone https://github.com/[GITHUB_USERNAME]/invoiceninja.git
cd invoiceninja

# Main branch'e push yapın
git push origin main
```

GitHub Actions otomatik olarak:
1. Docker image'ı build edecek (linux/amd64)
2. Artifact Registry'ye push edecek
3. Cloud Run'a deploy edecek

### Sonraki Deployments

Her `main` branch'e push işlemi otomatik deployment tetikler.

## Invoice Ninja İlk Kurulum

1. Cloud Run URL'ini açın: `https://[SERVICE_NAME]-[HASH].run.app`

2. Setup wizard otomatik açılacak

3. **Application Settings:**
   - URL: Otomatik dolu (https://[SERVICE_NAME]-[HASH].run.app)
   - HTTPS: ✓ Required

4. **Database Connection:**
   - Driver: MySQL
   - Host: [CLOUD_SQL_IP] (otomatik dolu)
   - Port: 3306
   - Database: ninja
   - Username: ninja
   - Password: [Secret Manager'dan otomatik]

5. **Test Connection** butonuna tıklayın

6. **User Details:**
   - First Name: Adınız
   - Last Name: Soyadınız
   - Email: admin@example.com
   - Password: Güçlü bir şifre

7. **Submit** butonuna tıklayın

8. Giriş yapın ve Invoice Ninja'yı kullanmaya başlayın!

## Veritabanı Bilgilerini Alma

```bash
# Database IP
gcloud sql instances describe invoiceninja-db --format="value(ipAddresses[0].ipAddress)"

# Database şifresi
gcloud secrets versions access latest --secret="invoiceninja-db-password"

# APP_KEY
gcloud secrets versions access latest --secret="invoiceninja-app-key"
```

## Sorun Giderme

### Logs Kontrolü

```bash
# Cloud Run logs
gcloud run services logs read invoiceninja --region=europe-west1 --limit=50

# Cloud SQL logs
gcloud sql operations list --instance=invoiceninja-db
```

### Service Restart

```bash
# Yeni revision oluştur (restart)
gcloud run services update invoiceninja --region=europe-west1
```

### Cache Temizleme

Container içinde cache temizleme otomatik yapılıyor (`start-cloudrun.sh`), ancak manuel yapmak isterseniz:

```bash
# Cloud Run revision'ı güncelleyin
gcloud run services update invoiceninja --region=europe-west1
```

## Maliyet Optimizasyonu

- **Cloud SQL**: db-f1-micro tier kullanılıyor (~$7/ay)
- **Cloud Run**: Sadece kullanıldığında ücretlendirilir
- **Artifact Registry**: İlk 500MB ücretsiz
- **Secret Manager**: İlk 6 secret ücretsiz

### Geliştirme Ortamı İçin

```bash
# Cloud SQL instance'ı durdur (maliyet tasarrufu)
gcloud sql instances patch invoiceninja-db --activation-policy=NEVER

# Tekrar başlat
gcloud sql instances patch invoiceninja-db --activation-policy=ALWAYS
```

## Güvenlik Notları

1. **HTTPS**: Varsayılan olarak aktif
2. **Database**: Public IP kullanılıyor, production için VPC kullanın
3. **Secrets**: Google Secret Manager'da güvenli şekilde saklanıyor
4. **Authentication**: Workload Identity Federation (service account key'siz)

## Yedekleme

### Otomatik Yedekleme (Cloud SQL)

```bash
# Yedekleme ayarları kontrol et
gcloud sql instances describe invoiceninja-db --format="value(settings.backupConfiguration)"

# Manuel yedekleme
gcloud sql backups create --instance=invoiceninja-db
```

## Güncelleme

Invoice Ninja güncellemeleri için:

```bash
# Yeni Invoice Ninja versiyonu çıktığında
# Dockerfile.fast içindeki base image'ı güncelleyin
FROM invoiceninja/invoiceninja:5.x.x

# Push yapın - otomatik deploy olacak
git add Dockerfile.fast
git commit -m "Update Invoice Ninja to 5.x.x"
git push origin main
```

## Destek ve Katkı

- **Invoice Ninja Resmi Dokümantasyon**: https://invoiceninja.github.io/
- **Invoice Ninja Forum**: https://forum.invoiceninja.com/
- **Issues**: GitHub Issues üzerinden

## Lisans

Invoice Ninja: Elastic License 2.0
Bu deployment konfigürasyonu: MIT License
