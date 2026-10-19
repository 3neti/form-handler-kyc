<script setup lang="ts">
import FormFlowActions from "@/pages/form-flow/core/components/FormFlowActions.vue";
import FormFlowAction from "@/pages/form-flow/core/components/FormFlowAction.vue";
import FormFlowStatusIcon from "@/pages/form-flow/core/components/FormFlowStatusIcon.vue";
import FormFlowPresentation from "@/pages/form-flow/core/components/FormFlowPresentation.vue";
import type { FormFlowPresentation as PresentationPolicy } from "@/pages/form-flow/core/components/formFlowPresentation";
import { computed, onMounted, onUnmounted, ref } from "vue";
import { router } from "@inertiajs/vue3";
import {
  CheckCircle,
  Clock,
  Loader2,
  RefreshCw,
  ShieldAlert,
  XCircle,
} from "lucide-vue-next";
import PublicLayout from "@/layouts/PublicLayout.vue";
import FormFlowScreen from "@/pages/form-flow/core/components/FormFlowScreen.vue";
import type { FormFlowUiVariant } from "@/pages/form-flow/core/components/formFlowUiVariant";

interface Props {
  preview_mode?: boolean;
  presentation?: PresentationPolicy | null;
  flow_id: string;
  transaction_id: string;
  initial_status?: string;
  can_retry?: boolean;
  status_url: string;
  retry_url: string;
  continue_url: string;
  polling_interval?: number;
  ui_variant?: FormFlowUiVariant | string | null;
}

const props = withDefaults(defineProps<Props>(), {
  initial_status: "processing",
  can_retry: false,
  polling_interval: 5,
  ui_variant: "default",
});

const status = ref(props.initial_status);
const rejectionReasons = ref<string[]>([]);
const canRetry = ref(props.can_retry);
const retryUrl = ref(props.retry_url);
const continueUrl = ref(props.continue_url);
const attemptCount = ref(1);
const statusMessage = ref<string | null>(null);
const retrying = ref(false);
let pollInterval: number | null = null;

const isPolling = computed(() =>
  ["pending", "processing", "needs_review"].includes(status.value),
);

function stopPolling() {
  if (pollInterval !== null) {
    window.clearInterval(pollInterval);
    pollInterval = null;
  }
}

function continueClaim() {
  if (props.preview_mode) return;
  stopPolling();
  window.setTimeout(() => router.visit(continueUrl.value), 1200);
}

async function checkStatus() {
  if (props.preview_mode) return;
  try {
    const response = await fetch(props.status_url, {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    const data = await response.json();

    status.value = data.status ?? "unavailable";
    rejectionReasons.value = data.rejection_reasons ?? [];
    canRetry.value = Boolean(data.can_retry);
    retryUrl.value = data.retry_url ?? retryUrl.value;
    continueUrl.value = data.continue_url ?? continueUrl.value;
    attemptCount.value = Number(data.attempt_count ?? attemptCount.value);
    statusMessage.value = data.error ?? null;

    if (status.value === "approved") {
      continueClaim();
    } else if (!isPolling.value) {
      stopPolling();
    }
  } catch {
    status.value = "unavailable";
    statusMessage.value =
      "We could not refresh the identity-check status. Your Pay Code remains protected.";
    stopPolling();
  }
}

function retryVerification() {
  if (props.preview_mode) return;
  if (!canRetry.value || retrying.value) {
    return;
  }

  retrying.value = true;
  router.post(
    retryUrl.value,
    {},
    {
      preserveScroll: true,
      onError: () => {
        statusMessage.value =
          "A new identity-check attempt could not be started. Please try again.";
      },
      onFinish: () => {
        retrying.value = false;
      },
    },
  );
}

onMounted(async () => {
  if (props.preview_mode) return;
  await checkStatus();

  if (isPolling.value) {
    pollInterval = window.setInterval(
      checkStatus,
      Math.max(2, props.polling_interval) * 1000,
    );
  }
});

onUnmounted(stopPolling);
</script>

<template>
  <FormFlowPresentation :presentation="presentation" screen="kyc">
    <PublicLayout>
      <FormFlowScreen
        title="Identity Check"
        description="Your Pay Code remains protected while identity verification is resolved."
        :variant="ui_variant"
      >
        <div class="grid gap-5 py-6 text-center">
          <template v-if="status === 'pending' || status === 'processing'">
            <Loader2 class="mx-auto size-12 animate-spin text-primary" />
            <div class="grid gap-1">
              <p class="text-lg font-semibold">Verifying your identity</p>
              <p class="text-sm text-muted-foreground">
                Keep this page open while HyperVerge processes the result.
              </p>
            </div>
          </template>

          <template v-else-if="status === 'approved'">
            <FormFlowStatusIcon role="success" />
            <div class="grid gap-1">
              <p class="text-lg font-semibold text-emerald-600">
                Identity verified
              </p>
              <p class="text-sm text-muted-foreground">
                Returning to your Pay Code claim…
              </p>
            </div>
          </template>

          <template v-else-if="status === 'needs_review'">
            <FormFlowStatusIcon role="pending" />
            <div class="grid gap-1">
              <p class="text-lg font-semibold text-amber-600">
                Review required
              </p>
              <p class="text-sm text-muted-foreground">
                HyperVerge could not approve this attempt automatically. You may
                wait for review or start a fresh identity check.
              </p>
            </div>
          </template>

          <template
            v-else-if="['rejected', 'cancelled', 'expired'].includes(status)"
          >
            <FormFlowStatusIcon role="error" />
            <div class="grid gap-1">
              <p class="text-lg font-semibold text-rose-600">
                Identity check not completed
              </p>
              <p class="text-sm text-muted-foreground">
                Correct the document, lighting, or camera issue and try again.
              </p>
            </div>
          </template>

          <template v-else-if="status === 'superseded'">
            <RefreshCw class="mx-auto size-14 text-sky-500" />
            <div class="grid gap-1">
              <p class="text-lg font-semibold text-sky-600">
                New attempt started
              </p>
              <p class="text-sm text-muted-foreground">
                This older attempt was retained for audit but can no longer
                advance the claim.
              </p>
            </div>
          </template>

          <template v-else>
            <FormFlowStatusIcon role="attention" />
            <div class="grid gap-1">
              <p class="text-lg font-semibold">
                Status temporarily unavailable
              </p>
              <p class="text-sm text-muted-foreground">
                {{
                  statusMessage ??
                  "Restart the Pay Code claim to resume identity verification."
                }}
              </p>
            </div>
          </template>

          <ul
            v-if="rejectionReasons.length > 0"
            class="mx-auto grid max-w-md gap-1 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-left text-sm text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200"
          >
            <li v-for="reason in rejectionReasons" :key="reason">
              • {{ reason }}
            </li>
          </ul>

          <p class="text-xs text-muted-foreground">
            Attempt {{ attemptCount }} · No payout occurs until identity is
            approved.
          </p>

          <FormFlowActions
            :show-secondary="false"
            v-if="canRetry || status === 'unavailable'"
          >
            <FormFlowAction
              v-if="canRetry"
              type="button"
              role="retry"
              label="Try identity check again"
              :disabled="retrying"
              :processing="retrying"
              processing-label="Starting…"
              @click="retryVerification"
            />
            <FormFlowAction
              v-if="status === 'unavailable'"
              type="button"
              role="retry"
              label="Check status"
              @click="checkStatus"
            />
          </FormFlowActions>
        </div>
      </FormFlowScreen>
    </PublicLayout>
  </FormFlowPresentation>
</template>
