# Laravel ML

[![tests](https://github.com/beziworld/laravel-ml/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-ml/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-ml.svg)](https://packagist.org/packages/beziworld/laravel-ml)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-ml.svg)](LICENSE.md)

Uczenie maszynowe w czystym PHP, zaprojektowane pod Laravela. Wszystko, co oferował
[php-ml](https://github.com/jorgecasas/php-ml) (klasyfikacja, regresja, klasteryzacja, Apriori, walidacja krzyżowa,
TF-IDF, preprocessing, metryki, zapis modeli, sieci neuronowe), zbudowane na utrzymywanym silniku
[Rubix ML](https://rubixml.com) 3 i opakowane w to, czego naprawdę potrzebuje aplikacja Laravel:

- zbiory danych prosto z Eloquenta (`fromQuery()` strumieniuje przez `lazy()`), kolekcji, tablic i plików CSV
- rzutowanie cech: liczby, kategorie, wartości logiczne, daty, tekst; imputacja, one-hot, normalizacja, TF-IDF
- definicje modeli jako klasy (`ChurnModel extends ClassificationModel`) albo płynny builder ad hoc
- presety: klasyfikator, regresor, klasteryzacja, wykrywanie anomalii, rekomendacje KNN, klasyfikacja tekstu
  (spam / sentyment)
- reguły asocjacyjne Apriori na kolekcjach Laravela (Rubix ich nie ma, php-ml miał)
- walidacja holdout i k-fold z typowanym raportem `TrainingReport` (accuracy, precision, recall, F1, MCC, macierz
  pomyłek, MAE, RMSE, R², silhouette, V-measure)
- wersjonowane modele na dowolnym dysku (`v1`, `v2`, ...), metadane JSON, promocja, rollback, czyszczenie starych wersji
- podpisane pliki modeli: weryfikacja SHA-256 + HMAC i ograniczona lista `allowed_classes` przed `unserialize()`
- predykcje jako DTO gotowe do JSON, trait `HasPredictions`, cast `AsPrediction`, reguły walidacji `ClassifiedAs`
  i `NotAnomalous`
- trening w kolejce (job `TrainModel`), zdarzenia, komendy Artisan, wykrywanie dryfu danych do nocnego monitoringu

[English documentation](README.md)

## Dlaczego ten pakiet

| | php-ml | sam Rubix ML | laravel-ml |
| --- | --- | --- | --- |
| Utrzymywany, PHP 8.3+ | nie (porzucony, ostatni push forka 2023) | tak | tak (Rubix 3.3) |
| Algorytmy | ~25 | 40+ | Rubix + Apriori + silhouette |
| Eloquent / kolekcje jako źródło danych | nie | nie | tak, strumieniowo |
| Rzutowanie cech (daty, enumy, tekst, braki) | ręcznie | ręcznie | deklaratywnie |
| Wersje modeli, promocja, rollback | nie | nie | tak |
| Bezpieczne wczytywanie plików modeli | nie | tylko suma crc32 | SHA-256 + HMAC + lista dozwolonych klas |
| Kolejka, zdarzenia, Artisan, reguły walidacji, casty | nie | nie | tak |

Istniejące pakiety dla Laravela (sprawdzone na Packagist i GitHubie w listopadzie 2026): wyszukiwanie
`php-ml laravel` nie zwraca nic; `torian257x/ai-php-rubix-wrap-laravel` (72 pobrania, ostatnia zmiana 2022) to cienki
wrapper bez zapisu, wersjonowania i walidacji; `divineomega/eloquent-attribute-value-prediction` przewiduje pojedynczy
atrybut modelu Eloquent. Żaden nie oferuje raportów walidacji krzyżowej, wersjonowanego zapisu, klasyfikacji tekstu,
rekomendacji, reguł asocjacyjnych ani wykrywania dryfu.

## Instalacja

```bash
composer require beziworld/laravel-ml
php artisan vendor:publish --tag=ml-config   # opcjonalnie
```

Wymagania: PHP 8.3+, Laravel 12 lub 13, `ext-zlib`. Opcjonalne
[rozszerzenie Tensor](https://github.com/RubixML/Tensor) wyraźnie przyspiesza modele liniowe i sieci neuronowe.

Pakiet działa bez konfiguracji. Opcjonalne ustawienia `.env`:

```dotenv
ML_DISK=local              # dysk na pliki modeli
ML_PATH=ml-models          # katalog na tym dysku
ML_AUTO_PROMOTE=true       # aktywuj każdą świeżo wytrenowaną wersję
ML_KEEP_VERSIONS=5         # liczba wersji zostawianych przez ml:prune
ML_VALIDATION=holdout      # holdout, kfold lub none
ML_HOLDOUT_RATIO=0.2
ML_FOLDS=5
ML_SEED=42                 # powtarzalny trening (mt_srand)
ML_MEMORY_LIMIT=2G         # ustawiany na czas treningu
ML_TIME_LIMIT=1800         # set_time_limit() i timeout joba w kolejce
ML_QUEUE=ml                # kolejka joba TrainModel
ML_QUEUE_CONNECTION=redis
ML_SIGNING_KEY=            # klucz HMAC plików modeli, domyślnie APP_KEY
ML_PREDICTION_EVENTS=false # zdarzenie PredictionMade
ML_PREDICTION_SAMPLE_RATE=0.05
```

Krótkie aliasy dla komend Artisan dodajesz w `config/ml.php`: `'models' => ['churn' => App\Ml\ChurnModel::class]`.

## Szybki start: przewidywanie odejść klientów (churn)

```php
namespace App\Ml;

use App\Models\User;
use Beziworld\Ml\Dataset\Feature;
use Beziworld\Ml\Models\ClassificationModel;
use Illuminate\Database\Eloquent\Builder;

final class ChurnModel extends ClassificationModel
{
    public function features(): array
    {
        return [
            'plan' => 'categorical',
            'age',                                   // typ wykrywany z danych
            'monthly_spend' => 'numeric',
            'last_login_at' => 'date',               // dni od epoki Unix
            Feature::numeric('orders', fn (User $user) => $user->orders_count),
        ];
    }

    public function label(): string
    {
        return 'churned';                           // atrybut bool, klasy "true" / "false"
    }

    public function query(): Builder
    {
        return User::query()->withCount('orders')->whereNotNull('churned');
    }
}
```

```php
use App\Ml\ChurnModel;
use Beziworld\Ml\Facades\Ml;
use Beziworld\Ml\Training\Validation;

$report = Ml::train(ChurnModel::class);                       // domyślnie walidacja holdout
$report = Ml::train(ChurnModel::class, validation: Validation::kFold(5));

$report->version;                    // "v3"
$report->metric('accuracy');         // 0.93
$report->metric('f1');
$report->confusionMatrix;            // ['true' => ['true' => 41, 'false' => 3], 'false' => [...]] (rzeczywista => przewidziana)

$prediction = Ml::predict(ChurnModel::class, $user);
$prediction->value;                  // "true"
$prediction->is(true);               // true
$prediction->probabilities;          // ['true' => 0.87, 'false' => 0.13]
$prediction->confidence;             // 0.87
$prediction->toArray();              // gotowe do odpowiedzi API

Ml::predictMany(ChurnModel::class, User::query()->active()->cursor());   // Collection<Prediction>
```

Domyślny klasyfikator to las losowy. Inny wybierzesz, nadpisując `estimator()` obiektem Rubix albo nazwą presetu
z `Estimators`:

```php
use Beziworld\Ml\Models\Estimators;
use Rubix\ML\Classifiers\KNearestNeighbors;

public function estimator(): Learner&Estimator
{
    return new KNearestNeighbors(7, weighted: true);   // albo Estimators::classifier('naive_bayes')
}
```

`estimator()` i `transformers()` są wywoływane raz na każdy fold walidacji, więc zawsze zwracaj nowe instancje.

## Szybki start: filtr spamu (klasyfikacja tekstu)

```php
use Beziworld\Ml\Models\TextClassificationModel;

final class SpamFilter extends TextClassificationModel
{
    public function text(): string { return 'body'; }
    public function label(): string { return 'is_spam'; }
    public function stopWords(): string { return 'polish'; }    // albo 'english', 'french' lub lista słów
    public function ngrams(): int { return 2; }                 // słowa i pary słów

    public function query(): Builder { return Message::query()->whereNotNull('is_spam'); }
}
```

Tekst jest zamieniany na małe litery, usuwane są słowa nieistotne (stop words), tokeny są liczone
(`WordCountVectorizer`) i ważone TF-IDF, a następnie trenowany jest klasyfikator softmax. Użycie w walidacji:

```php
use Beziworld\Ml\Rules\ClassifiedAs;

$request->validate([
    'body' => ['required', new ClassifiedAs(SpamFilter::class, 'false', minConfidence: 0.7)],
]);
```

## Szybki start: rekomendacje produktów

```php
use Beziworld\Ml\Models\RecommendationModel;

final class SimilarProducts extends RecommendationModel
{
    public function features(): array
    {
        return ['category_id' => 'categorical', 'price' => 'numeric', 'rating' => 'numeric', 'description' => 'text'];
    }

    public function query(): Builder { return Product::query()->where('active', true); }
    public function neighbours(): int { return 4; }
}

Ml::train(SimilarProducts::class);

$product->recommendations(SimilarProducts::class);    // Collection<Recommendation>, bez samego produktu
// [Recommendation{key: 18, distance: 0.21, similarity: 0.83}, ...]
```

Produkty są indeksowane w drzewie kulowym (ball tree) według przetworzonych wektorów cech; rekomendowane są najbliższe.
Do scenariusza „klienci, którzy kupili X, kupili też Y” użyj Apriori (niżej).

## Szybki start: wykrywanie anomalii w zamówieniach

```php
use Beziworld\Ml\Models\AnomalyDetectionModel;

final class OrderAnomalies extends AnomalyDetectionModel
{
    public function features(): array
    {
        return ['total' => 'numeric', 'items_count' => 'numeric', 'country' => 'categorical', 'created_at' => 'date'];
    }

    public function contamination(): ?float { return 0.02; }   // oczekiwany udział anomalii
    public function query(): Builder { return Order::query()->where('created_at', '>', now()->subYear()); }
}

$order->isAnomalous(OrderAnomalies::class);                 // bool
Ml::predict(OrderAnomalies::class, $order)->score;          // im wyżej, tym bardziej nietypowe

$request->validate(['order' => ['array', new NotAnomalous(OrderAnomalies::class)]]);
```

Etykieta jest opcjonalna: jeśli model zwraca `label()` (np. potwierdzone oszustwo), raport obok udziału anomalii
zawiera accuracy, precision, recall i F1.

## Szybki start: segmentacja klientów

```php
use Beziworld\Ml\Models\ClusteringModel;

final class CustomerSegments extends ClusteringModel
{
    public function features(): array { return ['recency_days' => 'numeric', 'orders' => 'numeric', 'revenue' => 'numeric']; }
    public function clusters(): int { return 4; }
    public function query(): Builder { return Customer::query(); }
}

$report = Ml::train(CustomerSegments::class);
$report->metric('silhouette');                               // -1..1, im wyżej, tym lepiej rozdzielone grupy

Ml::predict(CustomerSegments::class, $customer)->value;      // numer klastra, np. 2
```

## Modele ad hoc

Do szybkich eksperymentów klasa nie jest potrzebna:

```php
$report = Ml::model('lead-scoring')
    ->classifier('random_forest')                 // preset, obiekt Rubix lub domknięcie-fabryka
    ->features(['source' => 'categorical', 'visits' => 'numeric'])
    ->label('converted')
    ->using(Ml::dataset()->fromQuery(Lead::query()))
    ->train();

Ml::predict('lead-scoring', ['source' => 'ads', 'visits' => 7]);   // zapisane modele są wyszukiwane po nazwie
```

Presety: `classifier()` (`knn`, `naive_bayes`, `gaussian_nb`, `tree`, `random_forest`, `adaboost`, `bagging`,
`logistic`, `softmax`, `mlp`), `regressor()` (`ridge`, `least_squares`, `knn`, `tree`, `gradient_boost`, `adaline`,
`mlp`), `clusterer()` (`kmeans`, `dbscan`, `fuzzy_cmeans`, `gaussian_mixture`, `mean_shift`), `anomalyDetector()`
(`isolation_forest`, `local_outlier_factor`, `loda`, `gaussian_mle`, `robust_zscore`), `recommender()`,
`textClassifier()`. Sieci neuronowe: `Estimators::neuralNetwork([64, 32], epochs: 200)`.

## Zbiory danych i preprocessing

```php
$dataset = Ml::dataset()
    ->fromQuery(User::query(), features: ['age', 'plan', 'signed_up_at'], label: 'churned', chunk: 500)
    ->get();

Ml::dataset()->fromCollection($rows, ['area', 'rooms'], 'price');
Ml::dataset()->fromCsv(storage_path('houses.csv'), ['area', 'rooms'], 'price', delimiter: ';');   // strumieniowo
Ml::dataset()->fromArray([[1, 3], [1, 4], [2, 4]], ['a', 'a', 'b']);                            // jak w php-ml

$dataset->count(); $dataset->statistics(); $dataset->hash(); $dataset->toArray();
```

| Typ | Przyjmowane wartości | Wartość w próbce | Brak |
| --- | --- | --- | --- |
| `numeric` | int, float, string liczbowy, bool, enum z wartością int | float | `NAN` |
| `categorical` | string, int, bool, enum, Stringable | string | `"?"` |
| `boolean` | bool, 0/1, "yes"/"no", "true"/"false", "on"/"off" | 1.0 / 0.0 | `NAN` |
| `date` | DateTimeInterface (Carbon), data jako tekst, znacznik Unix | dni od epoki | `NAN` |
| `text` | string, tablica stringów | liczności tokenów / TF-IDF | `""` |

Automatyczny preprocessing (osobno dla każdej grupy cech, budowany ze schematu) uzupełnia braki (średnia /
najczęstsza kategoria), normalizuje kolumny liczbowe (domyślnie `zscore`, także `minmax`, `robust`, `maxabs`),
koduje kategorie one-hot i zamienia tekst na liczności tokenów ważone TF-IDF. Konfiguracja per model lub per zbiór:

```php
use Beziworld\Ml\Preprocessing\Preprocessing;

public function preprocessing(): ?Preprocessing
{
    return Preprocessing::defaults()->withNormalization('minmax')->withText(tfidf: true, stopWords: 'polish', ngrams: 2);
}

// albo pełna kontrola transformatorami Rubix:
public function transformers(): ?array
{
    return [new MissingDataImputer(), new OneHotEncoder(), new PrincipalComponentAnalysis(10)];
}

// eksploracja: przetworzony zbiór Rubix
Ml::dataset()->fromCollection($rows, ['plan', 'spend'])->impute()->oneHot()->normalize('minmax')->toRubix();
```

## Walidacja krzyżowa i raporty

`Validation::holdout(0.2)` (warstwowa dla klasyfikatorów), `Validation::kFold(5)` (warstwowa, metryki z predykcji
poza foldem treningowym) albo `Validation::none()`. Transformatory są dopasowywane wewnątrz każdego foldu, więc dane
testowe nie przeciekają do treningu. Po walidacji model końcowy jest trenowany na wszystkich próbkach.

| Zadanie | Metryki w `TrainingReport::$metrics` |
| --- | --- |
| klasyfikator | `accuracy`, `precision`, `recall`, `f1` (makro), `mcc`; do tego `confusionMatrix` |
| regresor | `mae`, `rmse`, `r2` |
| klasteryzacja | `silhouette`, `clusters`; z etykietą także `v_measure`, `homogeneity`, `completeness` |
| wykrywanie anomalii | `anomaly_rate`; z etykietą także `accuracy`, `precision`, `recall`, `f1` |
| rekomendacje | `items` |

`Ml::evaluate(ChurnModel::class)` ocenia aktywną (lub wskazaną) wersję na bieżących danych i zwraca
`EvaluationReport`.

## Wersje i zapis modeli

```
storage/app/ml-models/churn-model/v1/model.bin       model skompresowany gzip
storage/app/ml-models/churn-model/v1/metadata.json   cechy, etykieta, metryki, statystyki, skróty, wersje
storage/app/ml-models/churn-model/active.json        {"version": "v1", "previous": null, ...}
```

`metadata.json` zawiera cechy i ich typy, etykietę, metryki i sposób walidacji, `trained_at`, skrót SHA-256 zbioru
danych, statystyki cech, klasę estymatora, wersje pakietu, Rubix i PHP, sumę SHA-256, podpis HMAC i listę
serializowanych klas.

```php
Ml::versions(ChurnModel::class);           // list<ModelMetadata>, ->active wskazuje aktywną
Ml::promote(ChurnModel::class, 'v2');
Ml::rollback(ChurnModel::class);           // aktywuje wersję poprzedzającą aktywną
Ml::prune(ChurnModel::class, keep: 3);     // nigdy nie usuwa aktywnej wersji
Ml::predict(ChurnModel::class, $user, version: 'v1');
```

Wczytane modele są buforowane w obrębie procesu (długo działające workery i Octane używają ich ponownie).

## Bezpieczeństwo plików modeli

Pliki modeli to serializowane obiekty PHP, a `unserialize()` na danych kontrolowanych przez atakującego może
prowadzić do zdalnego wykonania kodu przez magiczne metody klas dostępnych w aplikacji. Format `RBX` z Rubix dodaje
jedynie sumę crc32, która wykrywa uszkodzenie, ale nie celową modyfikację. Dlatego pakiet:

1. sprawdza sumę SHA-256 pliku `model.bin` z metadanymi,
2. sprawdza podpis HMAC-SHA256 (sumy i listy klas) kluczem `ML_SIGNING_KEY` lub `APP_KEY`; niepodpisane pliki są
   odrzucane, dopóki `ML_REQUIRE_SIGNATURE=true` (domyślnie),
3. odrzuca każdą zapisaną klasę spoza `ml.security.allowed_namespaces` (`Rubix\ML\`, `Tensor\`, `OkBloomer\`,
   `Beziworld\Ml\`),
4. wywołuje `unserialize()` z `allowed_classes` ograniczonym do zapisanych klas.

Mimo to nigdy nie wczytuj plików modeli pochodzących od użytkowników, z uploadów ani z innych aplikacji, trzymaj dysk
modeli jako prywatny i zmieniaj klucz podpisu razem z ponownym treningiem (pliki podpisane starym kluczem są
odrzucane). Kto może odczytać `APP_KEY` i pisać na dysk modeli, może podrobić model.

## Dodatki Laravela

```bash
php artisan ml:train churn                      # alias z ml.models albo nazwa klasy
php artisan ml:train "App\Ml\ChurnModel" --folds=10 --no-promote
php artisan ml:train churn --queue              # job TrainModel w kolejce ML_QUEUE
php artisan ml:evaluate churn v2                # metryki i macierz pomyłek na bieżących danych
php artisan ml:models                           # wszystkie modele; ml:models churn pokazuje wersje z metrykami
php artisan ml:promote churn v2
php artisan ml:rollback churn
php artisan ml:prune --keep=3
php artisan ml:drift churn                      # kod wyjścia 1, gdy bieżące dane odpłynęły
```

Nocny retrening i monitoring w `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('ml:drift churn')->dailyAt('01:30')->onFailure(fn () => /* powiadom zespół */ null);
Schedule::command('ml:train churn --queue')->dailyAt('02:00');
Schedule::command('ml:prune --keep=7')->weekly();
```

Joby i zdarzenia:

```php
Ml::queue(ChurnModel::class, Validation::kFold(5));   // job TrainModel: tries = 1, timeout = ML_TIME_LIMIT
```

| Zdarzenie | Dane |
| --- | --- |
| `ModelTrained` | `TrainingReport $report`, `ModelMetadata $metadata` |
| `ModelPromoted` | `string $model`, `string $version`, `?string $previous` (także przy rollbacku) |
| `PredictionMade` | `Prediction $prediction`, `array $features`; tylko przy `ML_PREDICTION_EVENTS=true`, próbkowane według `ML_PREDICTION_SAMPLE_RATE` |

Integracja z Eloquentem:

```php
use Beziworld\Ml\Casts\AsPrediction;
use Beziworld\Ml\Concerns\HasPredictions;

class User extends Model
{
    use HasPredictions;

    protected function casts(): array
    {
        return ['churn_prediction' => AsPrediction::class];   // kolumna text / json
    }
}

$user->churn_prediction = $user->predict(ChurnModel::class);
$user->save();
$user->churn_prediction->confidence;
```

## Dryf danych

```php
$report = Ml::drift(ChurnModel::class, User::query()->where('created_at', '>', now()->subMonth())->cursor());

$report->drifted();           // bool
$report->driftedFeatures();   // ['monthly_spend']
$report->feature('plan');     // FeatureDrift: meanShift, distributionShift, missingShift
```

Cechy liczbowe, logiczne i daty odpływają, gdy średnia przesunie się o więcej niż `ML_DRIFT_MEAN_SHIFT` (0.5)
odchyleń standardowych z treningu; kategorie, gdy odległość całkowitej zmienności udziałów przekroczy
`ML_DRIFT_DISTRIBUTION_SHIFT` (0.2); tekst, gdy średnia długość zmieni się o ponad 50%; każda cecha, gdy udział
braków zmieni się o więcej niż `ML_DRIFT_MISSING_SHIFT` (0.1). To prosty alarm statystyczny, a nie zamiennik
monitorowania trafności modelu na danych z etykietami.

## Reguły asocjacyjne (Apriori)

```php
$apriori = Ml::apriori(support: 0.02, confidence: 0.4)
    ->train(Order::with('items')->lazy()->map(fn ($order) => $order->items->pluck('sku')));

$apriori->frequentItemsets();   // kolekcja ['items' => [...], 'support' => 0.031]
$apriori->rules();              // Collection<AssociationRule>: antecedent, consequent, support, confidence, lift
$apriori->predict(['SKU-1', 'SKU-7'], limit: 3);   // rekomendowane SKU dla koszyka
```

## Migracja z php-ml

| php-ml | laravel-ml |
| --- | --- |
| `Classification\KNearestNeighbors` | `Estimators::classifier('knn')` / `Rubix\ML\Classifiers\KNearestNeighbors` |
| `Classification\NaiveBayes` | `Estimators::classifier('naive_bayes')` (kategorie) lub `'gaussian_nb'` |
| `Classification\SVC` | `Rubix\ML\Classifiers\SVC` (wymaga rozszerzenia `svm`) |
| `Classification\DecisionTree` | `Estimators::classifier('tree')` (CART) |
| `Classification\Ensemble\RandomForest` / `Bagging` / `AdaBoost` | `'random_forest'` / `'bagging'` / `'adaboost'` |
| `Classification\Linear\LogisticRegression` | `'logistic'` (binarny) lub `'softmax'` (wiele klas) |
| `Classification\Linear\Adaline`, `Perceptron`, `DecisionStump` | `Estimators::regressor('adaline')`, `'logistic'`, `new ClassificationTree(1)` |
| `Classification\MLPClassifier` | `Estimators::neuralNetwork([warstwy...])` |
| `Regression\LeastSquares` | `Estimators::regressor('least_squares')` / `'ridge'` |
| `Regression\SVR` | `Rubix\ML\Regressors\SVR` (wymaga `svm`) |
| `Regression\DecisionTreeRegressor` | `Estimators::regressor('tree')` / `'gradient_boost'` |
| `Clustering\KMeans` / `DBSCAN` / `FuzzyCMeans` | `Estimators::clusterer('kmeans' / 'dbscan' / 'fuzzy_cmeans')`, `ClusteringModel` |
| `Association\Apriori` | `Ml::apriori()` (`Beziworld\Ml\Association\Apriori`) |
| `CrossValidation\RandomSplit` / `StratifiedRandomSplit` | `Validation::holdout()` (warstwowa dla klasyfikatorów), `Validation::kFold()` |
| `Metric\Accuracy`, `ConfusionMatrix`, `ClassificationReport` | `TrainingReport::$metrics`, `$confusionMatrix`; `MulticlassBreakdown` z Rubix |
| `Metric\Regression` | `mae`, `rmse`, `r2` w raporcie |
| `FeatureExtraction\TokenCountVectorizer` + tokenizery | cechy `text`, `Preprocessing::withText(ngrams: n)` |
| `FeatureExtraction\TfIdfTransformer` | `Preprocessing::withText(tfidf: true)` |
| `FeatureExtraction\StopWords\English/French/Polish` | `StopWords::english()/french()/polish()`, `stopWords: 'polish'` |
| `Preprocessing\Normalizer` | `Preprocessing::withNormalization('zscore' / 'minmax' / 'robust' / 'maxabs')` |
| `Preprocessing\Imputer` | `Preprocessing::withImputation()` (`MissingDataImputer`) |
| `Preprocessing\OneHotEncoder`, `LabelEncoder`, `NumberConverter` | automatycznie według typu cechy |
| `Preprocessing\LambdaTransformer`, `ColumnFilter` | `Feature::numeric('x', fn ($row) => ...)`, sama lista cech |
| `Pipeline`, `FeatureUnion` | definicja modelu + `transformers()`, `Preprocessing\ColumnTransformer` |
| `DimensionReduction\PCA` / `KernelPCA` / `LDA` | `PrincipalComponentAnalysis` / `LinearDiscriminantAnalysis` z Rubix w `transformers()` (bez kernel PCA) |
| `FeatureSelection\VarianceThreshold` / `SelectKBest` | brak; użyj ważności cech z drzew (`RanksFeatures`) lub własnego transformatora |
| `Dataset\ArrayDataset` / `CsvDataset` / `FilesDataset` | `Ml::dataset()->fromArray()` / `fromCsv()` / `fromCollection()` |
| `ModelManager::saveToFile()` / `restoreFromFile()` | automatyczny wersjonowany zapis, `Ml::versions()`, `Ml::promote()` |
| `Math\Distance`, `Statistic` | `Kernels\Distance\*` z Rubix, `Dataset::statistics()` |

## Wydajność

PHP wystarcza dla zbiorów, które ma większość aplikacji Laravel: do kilkuset tysięcy wierszy i kilkudziesięciu cech
trening na workerze kolejki trwa od sekund do minut. Warto pamiętać, że:

- cały zbiór jest w pamięci jako tablice PHP (około 100 do 200 bajtów na wartość); ustaw `ML_MEMORY_LIMIT` i zawężaj zapytanie
  lub ogranicz je przez `->limit()` przy milionach wierszy,
- trenuj w kolejce (`--queue`, `ML_TIME_LIMIT`), nigdy w żądaniu HTTP,
- walidacja k-fold trenuje k + 1 modeli; lasy losowe i gradient boosting to najwolniejsze presety,
- zainstaluj rozszerzenie Tensor dla modeli liniowych i sieci neuronowych; koszt predykcji KNN rośnie z liczbą danych,
- silhouette jest liczone na najwyżej 500 punktach, a wykrywanie dryfu przechowuje 50 najczęstszych kategorii,
- predykcje są tanie po pierwszym wczytaniu (model jest buforowany w procesie).

Wybierz Pythona (scikit-learn, XGBoost, PyTorch), gdy potrzebujesz treningu na GPU, uczenia głębokiego na obrazach
lub dźwięku, gradient boostingu na dziesiątkach milionów wierszy albo notebooków zespołu data science. Częsty podział
to ekstrakcja cech i serwowanie predykcji w Laravelu, a trening offline.

## Błędy

| Wyjątek | Kiedy |
| --- | --- |
| `InvalidConfiguration` | brakujące lub błędne ustawienia `ml.*` |
| `InvalidDataset` | złe typy wartości, brak etykiet, puste dane, za mało próbek do walidacji |
| `InvalidModelDefinition` | nieznany model, estymator niepasujący do zadania, zmiana cech od treningu, retrening modelu odtworzonego z dysku |
| `ModelNotFound` | brak aktywnej wersji lub nieznana wersja |
| `IntegrityCheckFailed` | nieudana weryfikacja sumy, podpisu lub listy dozwolonych klas |

Wszystkie wyjątki dziedziczą po `Beziworld\Ml\Exceptions\MlException`.

## Rozwój

```bash
composer install
composer ci   # pint --test, phpstan (poziom 8), pest
```

Testy używają małych deterministycznych zbiorów syntetycznych, zainicjowanego generatora liczb losowych, bazy SQLite
w pamięci oraz fałszywych dysków i kolejek. Sprawdzają progi metryk, a nie dokładne wartości zmiennoprzecinkowe.

## Autor

Pakiet stworzył i utrzymuje **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Zgłoszenia: [GitHub issues](https://github.com/beziworld/laravel-ml/issues)

Potrzebujesz integracji? Skontaktuj się przez [beziworld.eu](https://beziworld.eu).

## Licencja

MIT. Zobacz [LICENSE.md](LICENSE.md). Pakiet korzysta z [Rubix ML](https://github.com/RubixML/ML) (MIT) i nie jest
powiązany z projektem Rubix ML ani z autorami php-ml, ani przez nich wspierany.
