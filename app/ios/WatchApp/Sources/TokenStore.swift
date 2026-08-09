/// The tracker token, kept in the watch's Keychain.
///
/// This is a bearer token: whoever holds it can read and send this operator's net
/// traffic. Putting a copy on a second device widens the blast radius of a lost
/// watch, so it is stored more carefully than the phone stores its own copy —
/// `ThisDeviceOnly` keeps it out of iCloud Keychain and out of encrypted backups,
/// and it is wiped the moment sharing stops or the server rejects it.
import Foundation
import Security

enum TokenStore {
  private static let service = "org.marsaprs.aprsMap.watch"
  private static let account = "tracker_token"

  static func save(_ token: String) {
    guard !token.isEmpty else {
      wipe()
      return
    }
    guard let data = token.data(using: .utf8) else { return }
    let query: [String: Any] = [
      kSecClass as String: kSecClassGenericPassword,
      kSecAttrService as String: service,
      kSecAttrAccount as String: account,
    ]
    let attributes: [String: Any] = [
      kSecValueData as String: data,
      kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly,
    ]
    // Update first; SecItemAdd fails with errSecDuplicateItem once one exists.
    if SecItemUpdate(query as CFDictionary, attributes as CFDictionary) == errSecItemNotFound {
      var insert = query
      insert.merge(attributes) { current, _ in current }
      SecItemAdd(insert as CFDictionary, nil)
    }
  }

  static func load() -> String? {
    let query: [String: Any] = [
      kSecClass as String: kSecClassGenericPassword,
      kSecAttrService as String: service,
      kSecAttrAccount as String: account,
      kSecReturnData as String: true,
      kSecMatchLimit as String: kSecMatchLimitOne,
    ]
    var item: CFTypeRef?
    guard SecItemCopyMatching(query as CFDictionary, &item) == errSecSuccess,
          let data = item as? Data,
          let token = String(data: data, encoding: .utf8),
          !token.isEmpty
    else { return nil }
    return token
  }

  static func wipe() {
    let query: [String: Any] = [
      kSecClass as String: kSecClassGenericPassword,
      kSecAttrService as String: service,
      kSecAttrAccount as String: account,
    ]
    SecItemDelete(query as CFDictionary)
  }
}
