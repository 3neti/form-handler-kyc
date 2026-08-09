<script setup lang="ts">
import { computed } from "vue";
import { router } from "@inertiajs/vue3";
import PublicLayout from "@/layouts/PublicLayout.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { ShieldCheck, CheckCircle, AlertCircle } from "lucide-vue-next";
import FormFlowActions from "@/pages/form-flow/core/components/FormFlowActions.vue";
import FormFlowScreen from "@/pages/form-flow/core/components/FormFlowScreen.vue";
import type { FormFlowUiVariant } from "@/pages/form-flow/core/components/formFlowUiVariant";

interface PackageVersion {
  name: string;
  version: string;
}

interface Props {
  flow_id: string;
  step: string;
  config?: {
    title?: string;
    description?: string;
    use_fake?: boolean;
  };
  kyc_status?: string | null;
  mobile?: string | null;
  country?: string;
  ui_variant?: FormFlowUiVariant | string | null;
  action_placement?: "inline" | "bottom" | "bottom_sticky" | "viewport_bottom" | string | null;
  ui_layout?: Record<string, unknown> | null;
  app_name?: string | null;
  app_logo?: string | null;
  package_versions?: PackageVersion[] | Record<string, string> | null;
  show_package_versions?: boolean;
  preview_mode?: boolean;
}

const props = defineProps<Props>();

const DEBUG = false;

console.log("[KYCInitiatePage] mounted props", props);

const stepIndex = computed(() => Number.parseInt(props.step, 10));

const debugCallbackUrl = computed(() => {
  const cleanFlowId = props.flow_id.replace(/\./g, "-");
  const timestamp = Date.now();
  const transactionId = `formflow-${cleanFlowId}-${Math.floor(timestamp / 1000)}`;

  return `/form-flow/kyc/callback?transactionId=${transactionId}&status=auto_approved`;
});

function approvedPayload(extra: Record<string, any> = {}): Record<string, any> {
  return {
    data: {
      kyc: {
        transaction_id: extra.transaction_id ?? "existing",
        status: "approved",
        onboarding_url: null,
        needs_redirect: false,
        completed_at: extra.completed_at ?? new Date().toISOString(),
        rejection_reasons: null,
        ...extra,
      },

      transaction_id: extra.transaction_id ?? "existing",
      status: "approved",
      onboarding_url: null,
      needs_redirect: false,
      completed_at: extra.completed_at ?? new Date().toISOString(),
      rejection_reasons: null,
      ...extra,
    },
  };
}

const startKYC = () => {
  if (props.preview_mode) return;
  console.log("[KYCInitiatePage] Starting KYC verification", {
    flow_id: props.flow_id,
    step: props.step,
    step_index: stepIndex.value,
    mobile: props.mobile,
    country: props.country,
    use_fake: props.config?.use_fake,
  });

  if (props.config?.use_fake) {
    console.log("[KYCInitiatePage] FAKE MODE - submitting approved KYC step");

    router.post(
      `/form-flow/${props.flow_id}/step/${props.step}`,
      approvedPayload({
        mobile: props.mobile,
        country: props.country || "PH",
        transaction_id: `fake-${props.flow_id}`,
      }),
      {
        onSuccess: () => console.log("[KYCInitiatePage] fake submit success"),
        onError: (errors) =>
          console.error("[KYCInitiatePage] fake submit errors", errors),
        onFinish: () => console.log("[KYCInitiatePage] fake submit finished"),
      },
    );

    return;
  }

  console.log("[KYCInitiatePage] Real mode - initiating KYC provider");

  router.post(
    `/form-flow/${props.flow_id}/kyc/initiate`,
    {
      mobile: props.mobile,
      country: props.country || "PH",
      step: props.step,
      step_index: stepIndex.value,
    },
    {
      onSuccess: () => console.log("[KYCInitiatePage] initiate success"),
      onError: (errors) =>
        console.error("[KYCInitiatePage] initiate errors", errors),
      onFinish: () => console.log("[KYCInitiatePage] initiate finished"),
    },
  );
};

const continueFlow = () => {
  if (props.preview_mode) return;
  console.log("[KYCInitiatePage] already approved - continuing flow", {
    flow_id: props.flow_id,
    step: props.step,
  });

  router.post(
    `/form-flow/${props.flow_id}/step/${props.step}`,
    approvedPayload(),
    {
      onSuccess: () => console.log("[KYCInitiatePage] continue success"),
      onError: (errors) =>
        console.error("[KYCInitiatePage] continue errors", errors),
      onFinish: () => console.log("[KYCInitiatePage] continue finished"),
    },
  );
};
</script>

<template>
  <PublicLayout>
    <FormFlowScreen
      :title="config?.title || 'Identity Verification'"
      :description="config?.description || 'Verify your identity to continue'"
      :variant="ui_variant"
      :app-name="app_name"
      :app-logo="app_logo"
      :package-versions="package_versions"
      :show-package-versions="show_package_versions"
      version-context="kyc"
    >
      <template #icon>
        <ShieldCheck class="h-5 w-5" />
      </template>

      <div v-if="kyc_status === 'approved'" class="space-y-4">
        <Alert class="border-green-200 bg-green-50">
          <CheckCircle class="h-4 w-4 text-green-600" />
          <AlertDescription class="text-green-800">
            Your identity has already been verified.
          </AlertDescription>
        </Alert>

        <FormFlowActions
          :variant="ui_variant"
          :action-placement="action_placement"
          :show-secondary="false"
          primary-label="Continue"
          primary-type="button"
          @primary="continueFlow"
        />
      </div>

      <div v-else class="space-y-4">
        <Alert class="border-blue-200 bg-blue-50">
          <AlertCircle class="h-4 w-4 text-blue-600" />
          <AlertDescription class="text-blue-800">
            Identity verification is required. This process takes 1-2 minutes
            and uses your device camera.
          </AlertDescription>
        </Alert>

        <Alert
          v-if="DEBUG && !config?.use_fake"
          class="border-yellow-200 bg-yellow-50"
        >
          <AlertCircle class="h-4 w-4 text-yellow-600" />
          <AlertDescription class="text-yellow-800 text-xs">
            <strong>Debug:</strong> After completing KYC, if not redirected,
            manually visit:
            <br />
            <code class="block mt-2 p-2 bg-white rounded text-xs break-all">
              {{ debugCallbackUrl }}
            </code>
          </AlertDescription>
        </Alert>

        <FormFlowActions
          :variant="ui_variant"
          :action-placement="action_placement"
          :show-secondary="false"
          primary-label="Start Identity Verification"
          primary-type="button"
          @primary="startKYC"
        />
      </div>
    </FormFlowScreen>
  </PublicLayout>
</template>
