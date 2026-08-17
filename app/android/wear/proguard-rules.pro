# Keep rules for the Wear OS companion.
#
# Everything in this app is reached from Kotlin except the three classes the system
# instantiates by name out of the manifest. AGP keeps manifest-declared components on its
# own, but naming them here as well costs nothing and makes the dependency explicit — the
# failure mode if one were ever dropped is an app that installs, launches, and simply never
# hears from the phone again, with no error anywhere.
-keep class org.w6sg.aprsmap.wear.WearApp { *; }
-keep class org.w6sg.aprsmap.wear.MainActivity { *; }
-keep class org.w6sg.aprsmap.wear.PhoneListenerService { *; }

# The Data Layer reflects over its own model classes when decoding what crosses the link.
# Its own consumer rules cover this; repeated here because a message that silently fails to
# decode looks exactly like a phone that is out of range.
-keep class com.google.android.gms.wearable.** { *; }

# org.json is part of the platform, not the app, but R8 warns about it under
# -dontwarn-less optimisation.
-dontwarn org.json.**
