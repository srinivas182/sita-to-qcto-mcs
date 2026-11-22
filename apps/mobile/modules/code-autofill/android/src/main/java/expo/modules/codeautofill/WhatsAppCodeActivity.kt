package expo.modules.codeautofill

import android.app.Activity
import android.app.PendingIntent
import android.content.Intent
import android.os.Build
import android.os.Bundle

/**
 * Opened by WhatsApp's "Autofill" button. Accepts the code only when the attached
 * PendingIntent was created by WhatsApp itself, then returns to the app.
 */
class WhatsAppCodeActivity : Activity() {
  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    handle(intent)
    finish()
  }

  private fun handle(intent: Intent?) {
    if (intent == null) return
    val caller: PendingIntent? = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
      intent.getParcelableExtra(CodeAutofillModule.EXTRA_CALLER, PendingIntent::class.java)
    } else {
      @Suppress("DEPRECATION")
      intent.getParcelableExtra(CodeAutofillModule.EXTRA_CALLER)
    }
    if (caller?.creatorPackage !in CodeAutofillModule.WHATSAPP_PACKAGES) return

    val code = intent.getStringExtra("code") ?: return
    if (!CodeAutofillModule.CODE.matches(code)) return

    CodeAutofillModule.deliver(code, "whatsapp")
    packageManager.getLaunchIntentForPackage(packageName)?.let {
      it.addFlags(Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or Intent.FLAG_ACTIVITY_NEW_TASK)
      startActivity(it)
    }
  }
}
