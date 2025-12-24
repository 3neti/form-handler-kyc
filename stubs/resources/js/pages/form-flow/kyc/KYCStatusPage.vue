<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Loader2, CheckCircle, XCircle, Clock } from 'lucide-vue-next';

interface Props {
    flow_id: string;
    contact_id: number;
    transaction_id: string;
}

const props = defineProps<Props>();

const status = ref('processing');
const rejectionReasons = ref<string[]>([]);
let pollInterval: number | null = null;

const checkStatus = async () => {
    try {
        const response = await fetch(`/form-flow/${props.flow_id}/kyc/status`);
        const data = await response.json();
        
        status.value = data.status;
        rejectionReasons.value = data.rejection_reasons || [];
        
        if (data.status === 'approved') {
            // Stop polling and continue flow
            if (pollInterval) clearInterval(pollInterval);
            setTimeout(() => {
                // Submit KYC step data and continue
                router.post(`/form-flow/${props.flow_id}/step/kyc`, {
                    data: {
                        transaction_id: data.transaction_id,
                        status: 'approved',
                        onboarding_url: null,
                        needs_redirect: false,
                        completed_at: data.completed_at,
                        rejection_reasons: null,
                    }
                });
            }, 2000);
        } else if (data.status === 'rejected' || data.status === 'needs_review') {
            // Stop polling
            if (pollInterval) clearInterval(pollInterval);
        }
    } catch (error) {
        console.error('Failed to check KYC status:', error);
    }
};

onMounted(() => {
    checkStatus(); // Immediate check
    pollInterval = setInterval(checkStatus, 5000) as unknown as number; // Poll every 5 seconds
});

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});
</script>

<template>
    <PublicLayout>
        <div class="container mx-auto max-w-2xl px-4 py-8">
            <Card>
                <CardHeader>
                    <CardTitle>Verifying Your Identity</CardTitle>
                    <CardDescription>Please wait while we process your verification</CardDescription>
                </CardHeader>
                <CardContent>
                    <!-- Processing State -->
                    <div v-if="status === 'pending' || status === 'processing'" class="text-center py-8">
                        <Loader2 class="w-12 h-12 animate-spin mx-auto mb-4 text-primary" />
                        <p class="text-lg font-medium">Processing verification...</p>
                        <p class="text-sm text-muted-foreground mt-2">This usually takes 1-2 minutes</p>
                    </div>
                    
                    <!-- Approved State -->
                    <div v-else-if="status === 'approved'" class="text-center py-8">
                        <CheckCircle class="w-16 h-16 text-green-500 mx-auto mb-4" />
                        <p class="text-xl font-bold text-green-600">Identity Verified!</p>
                        <p class="text-sm text-muted-foreground mt-2">Continuing...</p>
                    </div>
                    
                    <!-- Rejected State -->
                    <div v-else-if="status === 'rejected'" class="text-center py-8">
                        <XCircle class="w-16 h-16 text-red-500 mx-auto mb-4" />
                        <p class="text-xl font-bold text-red-600">Verification Failed</p>
                        <ul v-if="rejectionReasons.length > 0" class="text-sm text-muted-foreground mt-4 text-left max-w-md mx-auto space-y-1">
                            <li v-for="reason in rejectionReasons" :key="reason">• {{ reason }}</li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground mt-2">Please contact support for assistance</p>
                    </div>
                    
                    <!-- Needs Review State -->
                    <div v-else-if="status === 'needs_review'" class="text-center py-8">
                        <Clock class="w-16 h-16 text-yellow-500 mx-auto mb-4" />
                        <p class="text-xl font-bold text-yellow-600">Manual Review Required</p>
                        <p class="text-sm text-muted-foreground mt-2">We'll notify you via SMS once reviewed</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </PublicLayout>
</template>
