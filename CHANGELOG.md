# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Fluent datasets from Eloquent queries (streamed with `lazy()`), collections, php-ml style arrays and CSV files,
  with numeric, categorical, boolean, date and text feature casting and type inference.
- Automatic preprocessing per feature group: imputation, normalisation, one-hot encoding, token counts, TF-IDF,
  English, Polish and French stop words, n-grams (`ColumnTransformer`, `TransformerFactory`).
- Model definitions (`MachineLearningModel`) with presets for classification, regression, clustering, anomaly
  detection, KNN recommendations and text classification, plus an ad-hoc `ModelBuilder` and `Estimators` presets.
- Training with stratified holdout and k-fold cross validation, typed `TrainingReport` and `EvaluationReport`
  (accuracy, precision, recall, F1, MCC, confusion matrix, MAE, RMSE, R2, silhouette, V-measure, anomaly metrics).
- Versioned model storage on a filesystem disk with metadata JSON, promotion, rollback and pruning.
- Model file verification: SHA-256 checksum, HMAC-SHA256 signature and a namespace-restricted `allowed_classes`
  list before `unserialize()`.
- Predictions as `Prediction` DTOs, probabilities, anomaly scores, `HasPredictions` trait, `AsPrediction` cast,
  `ClassifiedAs` and `NotAnomalous` validation rules.
- Apriori association rules over collections.
- Data drift check against stored feature statistics.
- `TrainModel` queued job, `ModelTrained`, `ModelPromoted` and `PredictionMade` events.
- Artisan commands `ml:train`, `ml:evaluate`, `ml:models`, `ml:promote`, `ml:rollback`, `ml:prune`, `ml:drift`.
