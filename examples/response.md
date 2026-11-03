1. ANALYSIS
`GET /admin/users` returns 403 for users that have `users-read`. `UserPolicy`
overrides `viewAny` with a permission that no role grants.

2. CHANGE_MANIFEST
```json
{
  "evidence": "tests/Feature/Filament/UserResourceTest::test_list_users fails: GET /admin/users returns 403 for a user with the users-read permission",
  "root_cause": "UserPolicy::viewAny checks the users-list permission, but BasePolicy maps viewAny to {group}-read",
  "fix": "Remove the viewAny override in UserPolicy so the BasePolicy mapping (users-read) is used",
  "prediction": {
    "expected_result": "UserResourceTest passes; no other test changes",
    "risk_level": "low",
    "metrics": { "test_pass": true, "failures": 0 }
  }
}
```

3. CODE_DIFF
```diff
--- a/app/Policies/UserPolicy.php
+++ b/app/Policies/UserPolicy.php
@@ -10,11 +10,6 @@ class UserPolicy extends BasePolicy
 {
     protected string $group = 'users';
 
-    public function viewAny(User $user): bool
-    {
-        return $user->hasPermission('users-list');
-    }
-
     public function assign(User $user): bool
     {
         return $user->hasPermission('user-grant');
```
