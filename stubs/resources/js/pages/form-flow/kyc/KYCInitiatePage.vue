<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { ShieldCheck, CheckCircle, AlertCircle } from 'lucide-vue-next';

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
}

const props = defineProps<Props>();

const DEBUG = false;

console.log('[KYCInitiatePage] mounted props', props);

const stepIndex = computed(() => Number.parseInt(props.step, 10));

const debugCallbackUrl = computed(() => {
  const cleanFlowId = props.flow_id.replace(/\./g, '-');
  const timestamp = Date.now();
  const transactionId = `formflow-${cleanFlowId}-${Math.floor(timestamp / 1000)}`;

  return `/form-flow/kyc/callback?transactionId=${transactionId}&status=auto_approved`;
});

function approvedPayload(extra: Record<string, unknown> = {}) {
  return {
    data: {
      kyc: {
        transaction_id: extra.transaction_id ?? 'existing',
        status: 'approved',
        onboarding_url: null,
        needs_redirect: false,
        completed_at: extra.completed_at ?? new Date().toISOString(),
        rejection_reasons: null,
        ...extra,
      },

      transaction_id: extra.transaction_id ?? 'existing',
      status: 'approved',
      onboarding_url: null,
      needs_redirect: false,
      completed_at: extra.completed_at ?? new Date().toISOString(),
      rejection_reasons: null,
      ...extra,
    },
  };
}

const startKYC = () => {
  console.log('[KYCInitiatePage] Starting KYC verification', {
    flow_id: props.flow_id,
    step: props.step,
    step_index: stepIndex.value,
    mobile: props.mobile,
    country: props.country,
    use_fake: props.config?.use_fake,
  });

  if (props.config?.use_fake) {
    console.log('[KYCInitiatePage] FAKE MODE - submitting approved KYC step');

    router.post(
        `/form-flow/${props.flow_id}/step/${props.step}`,
        approvedPayload({
          mobile: props.mobile,
          country: props.country || 'PH',
          transaction_id: `fake-${props.flow_id}`,
        }),
        {
          onSuccess: () => console.log('[KYCInitiatePage] fake submit success'),
          onError: (errors) => console.error('[KYCInitiatePage] fake submit errors', errors),
          onFinish: () => console.log('[KYCInitiatePage] fake submit finished'),
        },
    );

    return;
  }

  console.log('[KYCInitiatePage] Real mode - initiating KYC provider');

  router.post(
      `/form-flow/${props.flow_id}/kyc/initiate`,
      {
        mobile: props.mobile,
        country: props.country || 'PH',
        step: props.step,
        step_index: stepIndex.value,
      },
      {
        onSuccess: () => console.log('[KYCInitiatePage] initiate success'),
        onError: (errors) => console.error('[KYCInitiatePage] initiate errors', errors),
        onFinish: () => console.log('[KYCInitiatePage] initiate finished'),
      },
  );
};

const continueFlow = () => {
  console.log('[KYCInitiatePage] already approved - continuing flow', {
    flow_id: props.flow_id,
    step: props.step,
  });

  router.post(
      `/form-flow/${props.flow_id}/step/${props.step}`,
      approvedPayload(),
      {
        onSuccess: () => console.log('[KYCInitiatePage] continue success'),
        onError: (errors) => console.error('[KYCInitiatePage] continue errors', errors),
        onFinish: () => console.log('[KYCInitiatePage] continue finished'),
      },
  );
};
</script>

<template>
  <PublicLayout>
    <div class="container mx-auto max-w-2xl px-4 py-8">
      <Card>
        <CardHeader>
          <CardTitle class="flex items-center gap-2">
            <ShieldCheck class="h-5 w-5" />
            {{ config?.title || 'Identity Verification' }}
          </CardTitle>
          <CardDescription>
            {{ config?.description || 'Verify your identity to continue' }}
          </CardDescription>
        </CardHeader>

        <CardContent>
          <div v-if="kyc_status === 'approved'" class="space-y-4">
            <Alert class="border-green-200 bg-green-50">
              <CheckCircle class="h-4 w-4 text-green-600" />
              <AlertDescription class="text-green-800">
                Your identity has already been verified.
              </AlertDescription>
            </Alert>

            <Button @click="continueFlow" size="lg" class="w-full">
              Continue
            </Button>
          </div>

          <div v-else class="space-y-4">
            <Alert class="border-blue-200 bg-blue-50">
              <AlertCircle class="h-4 w-4 text-blue-600" />
              <AlertDescription class="text-blue-800">
                Identity verification is required. This process takes 1-2 minutes and uses your device camera.
              </AlertDescription>
            </Alert>

            <Alert v-if="DEBUG && !config?.use_fake" class="border-yellow-200 bg-yellow-50">
              <AlertCircle class="h-4 w-4 text-yellow-600" />
              <AlertDescription class="text-yellow-800 text-xs">
                <strong>Debug:</strong> After completing KYC, if not redirected, manually visit:
                <br />
                <code class="block mt-2 p-2 bg-white rounded text-xs break-all">
                  {{ debugCallbackUrl }}
                </code>
              </AlertDescription>
            </Alert>

            <Button @click="startKYC" size="lg" class="w-full">
              Start Identity Verification
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  </PublicLayout>
</template>