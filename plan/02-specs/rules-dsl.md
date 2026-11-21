# Rules DSL (مطابقة)
```json
{ "id": "cosing-III-xxx", "scope": {"productTypes": ["shampoo","body_wash"], "rinseOff": true},
  "target": {"substanceCas": "XXXX-XX-X"}, "operator": "<=", "limit": "0.5", "unit": "%w/w",
  "group": "annexIII-sum-xx", "source": "CosIng Annex III ref xx", "message_ar": "..." }
```
- النتيجة: PASS / FAIL / UNKNOWN (داتا ناقصة). UNKNOWN عمره ما يتحسب PASS.
- `group`: قواعد مجموع النسب Σ(cᵢ/limitᵢ) ≤ 1.
- قواعد المواصفات المصرية تتكتب بعد شراء النصوص (N-02) ومراجعتها.
