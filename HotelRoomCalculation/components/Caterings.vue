<template>
  <div>
    <div class="card">
      <div class="card-body">
        <transition-group name="fade" mode="out-in">
          <catering-variant
              v-for="(variant, index) in variants"
              :key="variant.id"
              :variant="variant"
              :catering-types="cateringTypes"
              :i="index"
              @updateVariants="getRoomCaterings"
          ></catering-variant>
        </transition-group>
      </div>
    </div>
    <a href="#" class="btn btn-link btn-block mt-4" @click.prevent="addVariant">{{tr.get('catering.add_variant')}}</a>
  </div>
</template>

<script  type="text/babel">
  import CateringVariant from './CateringVariant.vue';

  export default {
    data() {
      return {
        variants: []
      }
    },
    components: {
      'catering-variant': CateringVariant
    },
    props: ['cateringTypes', 'roomTypeId'],
    methods: {
      getRoomCaterings() {
        this.variants = [];

        Vue.axios.post('getRoomCaterings', {id: this.roomTypeId})
          .then((response) => {
            this.variants = response.data.caterings;
          });
      },
      addVariant() {
        Vue.axios.post('addRoomCatering', {id: this.roomTypeId})
        .then((response) => {
          if(!response.data.result) {
            alert("Wasn't created");
          } else {
            this.getRoomCaterings();
          }
        });
      }
    },
    created() {
      this.getRoomCaterings();
    }
  }
</script>
